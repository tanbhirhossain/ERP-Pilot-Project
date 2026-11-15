<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\HrmLeaveBalance;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;

/**
 * Closing a leave year: freeze what each employee earned, took and carries into the next year.
 *
 * The previous version wrote a snapshot nobody read (carry-forward was absent from the balance
 * formula), marked every row `is_closed = false` so a closed year still looked open, and offered a
 * "preview" that could not be run before the irreversible close. It also seeded next year's opening
 * balance from carry while the formula that reads opening balances ignored it — three separate breaks.
 *
 * Now: `preview()` computes the whole result without writing, `close()` writes it inside one
 * transaction, a closed row is genuinely closed and therefore immune to later back-dating, and
 * `reopen()` exists because the screen had been promising an undo it did not have.
 */
final class LeaveYearEndService
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * What closing `$year` would do, per leave type — nothing is written.
     *
     * This is what the year-end screen shows before anybody presses Close: how many employees, how
     * many days would carry, how many would lapse, and what would become encashable.
     */
    public function preview(int $year): array
    {
        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get();
        $employees = $this->employeesInScope();
        $out = [];
        $totals = ['employees' => $employees->count(), 'entitlement' => 0.0, 'availed' => 0.0, 'pending' => 0.0, 'carry' => 0.0, 'lapsed' => 0.0, 'encashable' => 0.0];

        foreach ($types as $type) {
            $setting = LeaveTypeSetting::firstOrCreate(['leave_type_id' => $type->id], []);
            $row = [
                'leave_type_id' => $type->id,
                'leave_type' => $type->name,
                'code' => $type->code,
                'color' => $type->color,
                'is_carry_forward' => (bool) $setting->is_carry_forward,
                'max_carry_days' => $setting->max_carry_days === null ? null : (float) $setting->max_carry_days,
                'is_encashable' => (bool) $setting->is_encashable,
                'encash_max_days' => $setting->encash_max_days === null ? null : (float) $setting->encash_max_days,
                'employees' => 0,
                'entitlement' => 0.0,
                'availed' => 0.0,
                'pending' => 0.0,
                'carry' => 0.0,
                'lapsed' => 0.0,
                'encashable' => 0.0,
                'already_closed' => 0,
            ];

            foreach ($employees as $employee) {
                $computed = $this->computeFor($employee->employee_id, $type, $setting, $year);
                if ($computed === null) {
                    continue;
                }
                $row['employees']++;
                $row['already_closed'] += $computed['is_closed'] ? 1 : 0;
                $row['entitlement'] += $computed['entitlement'];
                $row['availed'] += $computed['availed'];
                $row['pending'] += $computed['pending'];
                $row['carry'] += $computed['carry_forward'];
                $row['lapsed'] += $computed['lapsed'];
                $row['encashable'] += $computed['encashable'];
            }

            foreach (['entitlement', 'availed', 'pending', 'carry', 'lapsed', 'encashable'] as $key) {
                $row[$key] = round($row[$key], 2);
                $totals[$key] += $row[$key];
            }
            $out[] = $row;
        }

        foreach (['entitlement', 'availed', 'pending', 'carry', 'lapsed', 'encashable'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }

        return [
            'year' => $year,
            'types' => $out,
            'totals' => $totals,
            'closed' => $this->closedCount($year),
            'next_year_openings' => round((float) HrmLeaveBalance::query()->where('year', $year + 1)->sum('opening_balance'), 2),
        ];
    }

    /**
     * Close the year: write the frozen snapshot and hand carry-forward to the next year.
     *
     * Idempotent — a second run updates the open rows and leaves closed ones untouched, so an
     * administrator can safely re-run after fixing a back-dated request.
     */
    public function close(int $year, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($year, $actorId) {
            $types = LeaveType::query()->where('is_active', true)->get();
            $employees = $this->employeesInScope();
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($employees as $employee) {
                foreach ($types as $type) {
                    $setting = LeaveTypeSetting::firstOrCreate(['leave_type_id' => $type->id], []);
                    $computed = $this->computeFor($employee->employee_id, $type, $setting, $year, $actorId);
                    if ($computed === null) {
                        continue;
                    }

                    $existing = HrmLeaveBalance::query()
                        ->where('employee_id', $employee->employee_id)
                        ->where('leave_type_id', $type->id)
                        ->where('year', $year)
                        ->first();

                    if ($existing !== null && $existing->is_closed) {
                        $skipped++;
                        continue;
                    }

                    $payload = $computed;
                    unset($payload['encashable']);

                    if ($existing !== null) {
                        $existing->update($payload);
                        $updated++;
                    } else {
                        HrmLeaveBalance::create($payload);
                        $created++;
                    }

                    // Hand the carry over. The next year keeps its own entitlement; opening is the carried days.
                    $next = HrmLeaveBalance::query()->firstOrNew([
                        'employee_id' => $employee->employee_id,
                        'leave_type_id' => $type->id,
                        'year' => $year + 1,
                    ]);
                    if (! $next->exists || ! $next->is_closed) {
                        $next->opening_balance = round($computed['carry_forward'], 2);
                        $next->save();
                    }
                }
            }

            $this->balances->forgetSnapshots();

            return [
                'year' => $year,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'employees' => $employees->count(),
                'types' => $types->count(),
            ];
        });
    }

    /**
     * Reopen a closed year so a corrected application can still change it.
     *
     * The screen told administrators the close could not be undone "without admin"; there was no such
     * path. Reopening leaves the snapshot rows in place (they still hold the closed figures) and simply
     * clears the closed flag, after which the live calculation takes over again.
     */
    public function reopen(int $year, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($year) {
            $count = HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->count();
            if ($count === 0) {
                throw ValidationException::withMessages(['year' => "The {$year} leave year is not closed, so there is nothing to reopen."]);
            }

            HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->update([
                'is_closed' => false,
                'closed_by' => null,
                'closed_at' => null,
            ]);
            HrmLeaveBalance::query()->where('year', $year + 1)->update(['opening_balance' => 0]);
            $this->balances->forgetSnapshots();

            return ['year' => $year, 'reopened' => $count];
        });
    }

    /**
     * One employee's year-end figures for one type.
     *
     * `available` carries what is left; carry is capped by policy, everything else lapses. Encashable is
     * a subset of the carry (a day cannot both be encashed and be spent next year).
     */
    private function computeFor(string $employeeId, LeaveType $type, LeaveTypeSetting $setting, int $year, ?int $actorId = null): ?array
    {
        $exists = HrmLeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->first();

        $balance = $this->balances->balance($employeeId, $type->id, $year);

        $entitlement = round((float) $balance['entitlement'], 2);
        $opening = round((float) $balance['opening'], 2);
        $carried = round((float) $balance['carry_forward'], 2);
        $availed = round((float) $balance['approved'], 2);
        $pending = round((float) $balance['pending'], 2);
        $unused = round(max(0, $opening + $entitlement + $carried - $availed - $pending), 2);

        $carry = 0.0;
        $lapsed = 0.0;
        if ($setting->is_carry_forward) {
            $carry = $setting->max_carry_days === null ? $unused : min($unused, (float) $setting->max_carry_days);
        }
        $carry = round($carry, 2);
        $lapsed = round(max(0, $unused - $carry), 2);

        $encashable = 0.0;
        if ($setting->is_encashable) {
            $encashable = $setting->encash_max_days === null ? $carry : min($carry, (float) $setting->encash_max_days);
        }

        return [
            'employee_id' => $employeeId,
            'leave_type_id' => $type->id,
            'year' => $year,
            'opening_balance' => $opening,
            'entitlement' => $entitlement,
            'carry_forward' => $carry,
            'availed' => $availed,
            'pending' => $pending,
            'encashed' => 0.0,
            'lapsed' => $lapsed,
            'available' => $unused,
            'is_closed' => true,
            'closed_by' => $actorId,
            'closed_at' => now(),
            'encashable' => round($encashable, 2),
        ];
    }

    private function employeesInScope()
    {
        return Employee::query()
            ->where('is_active', true)
            ->orderBy('employee_id')
            ->get(['employee_id', 'full_name', 'first_name', 'last_name', 'department_id', 'joining_date']);
    }

    private function closedCount(int $year): int
    {
        return HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->count();
    }

    /** Per-type totals of an already-closed (or partly closed) year. */
    public function yearSummary(int $year): array
    {
        return HrmLeaveBalance::query()
            ->join('leave_types', 'leave_types.id', '=', 'hrm_leave_balances.leave_type_id')
            ->where('hrm_leave_balances.year', $year)
            ->groupBy('leave_types.name', 'leave_types.id')
            ->selectRaw('leave_types.name as leave_type, leave_types.id as leave_type_id, COUNT(DISTINCT hrm_leave_balances.employee_id) as emp_count, SUM(hrm_leave_balances.entitlement) as entitlement, SUM(hrm_leave_balances.availed) as availed, SUM(hrm_leave_balances.pending) as pending, SUM(hrm_leave_balances.carry_forward) as carry, SUM(hrm_leave_balances.lapsed) as lapsed, SUM(hrm_leave_balances.available) as available')
            ->orderBy('leave_types.name')
            ->get()
            ->map(fn ($row): array => [
                'leave_type_id' => (int) $row->leave_type_id,
                'leave_type' => $row->leave_type,
                'emp_count' => (int) $row->emp_count,
                'entitlement' => round((float) $row->entitlement, 2),
                'availed' => round((float) $row->availed, 2),
                'pending' => round((float) $row->pending, 2),
                'carry' => round((float) $row->carry, 2),
                'lapsed' => round((float) $row->lapsed, 2),
                'available' => round((float) $row->available, 2),
            ])
            ->all();
    }

    /** Days that could be paid out at settlement, with the rate basis the type's policy names. */
    public function encashmentPreview(string $employeeId, int $year): array
    {
        $employee = Employee::query()->where('employee_id', $employeeId)->first(['employee_id', 'full_name', 'first_name', 'last_name', 'joining_date']);
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'That employee does not exist.']);
        }

        /*
         * Salary lives in `employee_salaries`, versioned by `is_current` — the employee row carries no
         * salary at all. Reading `$employee->basic_salary` returns null on this schema, which would have
         * priced every encashment at zero and looked like a policy problem rather than a bug.
         */
        $salary = DB::table('employee_salaries')
            ->where('employee_id', $employeeId)
            ->where('is_current', true)
            ->orderByDesc('effective_from')
            ->first(['basic_salary', 'gross_salary'])
            ?? DB::table('employee_salaries')
                ->where('employee_id', $employeeId)
                ->orderByDesc('effective_from')
                ->first(['basic_salary', 'gross_salary']);

        $basicSalary = (float) ($salary->basic_salary ?? 0);
        $grossSalary = (float) ($salary->gross_salary ?? 0);

        $lines = [];
        foreach (LeaveType::query()->where('is_active', true)->orderBy('name')->get() as $type) {
            $setting = LeaveTypeSetting::firstOrCreate(['leave_type_id' => $type->id], []);
            if (! $setting->is_encashable) {
                continue;
            }

            $balance = $this->balances->balance($employeeId, $type->id, $year);
            $days = round((float) $balance['available'], 2);
            if ($setting->encash_max_days !== null) {
                $days = min($days, (float) $setting->encash_max_days);
            }
            if ($days <= 0) {
                continue;
            }

            $component = $setting->encash_salary_component ?: 'basic';
            $basis = $component === 'gross' ? (float) $grossSalary : (float) $basicSalary;
            $perDay = $basis > 0 ? round($basis / 30, 2) : 0.0;

            $lines[] = [
                'leave_type_id' => $type->id,
                'leave_type' => $type->name,
                'code' => $type->code,
                'days' => round($days, 2),
                'component' => $component,
                'basis' => round($basis, 2),
                'per_day' => $perDay,
                'amount' => round($days * $perDay, 2),
                'max_days' => $setting->encash_max_days === null ? null : (float) $setting->encash_max_days,
                'capped' => $setting->encash_max_days !== null && (float) $balance['available'] > (float) $setting->encash_max_days,
            ];
        }

        return [
            'employee' => [
                'employee_id' => $employee->employee_id,
                'name' => $employee->full_name ?: trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'basic_salary' => round((float) $basicSalary, 2),
                'gross_salary' => round((float) $grossSalary, 2),
                'has_salary_record' => $salary !== null,
            ],
            'year' => $year,
            'lines' => $lines,
            'total_days' => round(array_sum(array_column($lines, 'days')), 2),
            'total_amount' => round(array_sum(array_column($lines, 'amount')), 2),
        ];
    }

    /** Kept for settlement flows that ask the service directly. */
    public function encashableForSettlement(string $employeeId, ?int $asOfYear = null): array
    {
        $year = $asOfYear ?? (int) now()->format('Y');

        return array_map(fn (array $line): array => [
            'leave_type_id' => $line['leave_type_id'],
            'leave_type' => $line['leave_type'],
            'encash_days' => $line['days'],
            'component' => $line['component'],
            'year' => $year,
        ], $this->encashmentPreview($employeeId, $year)['lines']);
    }
}
