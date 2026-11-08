<?php

namespace Modules\PAYROLL\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfLedger;
use Modules\PAYROLL\Models\PfPolicy;

final class ProvidentFundService
{
    public function accounts(int $perPage = 25, ?string $search = null): LengthAwarePaginator
    {
        return PfAccount::query()->with(['policy:id,name', 'employee:id,employee_id,full_name'])->when($search, function ($q) use ($search): void {
            $needle = '%'.addcslashes(trim($search), '%_').'%';
            $q->where(fn ($g) => $g->where('employee_id', 'like', $needle)->orWhere('employee_name', 'like', $needle)->orWhereHas('employee', fn ($employee) => $employee->where('full_name', 'like', $needle)->orWhere('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle)));
        })
            ->withSum(['ledgers as ledger_balance' => fn ($query) => $query], 'amount')
            ->latest('id')->paginate($perPage)->withQueryString();
    }

    public function policies()
    {
        return PfPolicy::query()->latest('id')->get();
    }

    public function savePolicy(array $data): PfPolicy
    {
        foreach (['employee_contribution_percent', 'employer_contribution_percent'] as $field) {
            if ((float) $data[$field] < 0 || (float) $data[$field] > 100) {
                throw new \InvalidArgumentException('PF contribution percentages must be between 0 and 100.');
            }
        }

        return PfPolicy::query()->create($data);
    }

    /** Allocate one or more new PF accounts without changing existing employee accounts. */
    public function allocateAccounts(int $policyId, array $employeeIds = [], bool $allActive = false): array
    {
        $policy = PfPolicy::query()->where('is_active', true)->findOrFail($policyId);
        $ids = $allActive
            ? Employee::query()->where('is_active', true)->whereIn('status', ['Active', 'On Leave'])->orderBy('employee_id')->pluck('employee_id')->all()
            : array_values(array_unique(array_map('strval', $employeeIds)));
        if (! $ids) {
            return ['created' => 0, 'skipped' => 0];
        }

        $created = 0;
        $skipped = 0;
        foreach (array_chunk($ids, 250) as $chunk) {
            $counts = DB::transaction(function () use ($chunk, $policy): array {
                $existing = PfAccount::query()->whereIn('employee_id', $chunk)->pluck('employee_id')->map('strval')->all();
                $existingSet = array_fill_keys($existing, true);
                $employees = Employee::query()->whereIn('employee_id', $chunk)
                    ->get(['employee_id', 'full_name', 'joining_date'])->keyBy('employee_id');
                $rows = [];
                $skippedExisting = 0;

                foreach ($chunk as $employeeId) {
                    $employeeId = (string) $employeeId;
                    if (isset($existingSet[$employeeId])) {
                        $skippedExisting++;

                        continue;
                    }

                    $employee = $employees->get($employeeId);
                    if ($employee === null) {
                        $skippedExisting++;

                        continue;
                    }

                    $eligibleFrom = $employee->joining_date
                        ? Carbon::parse($employee->joining_date)->addMonthsNoOverflow((int) $policy->minimum_service_months)->toDateString()
                        : null;
                    $timestamp = now()->toDateTimeString();
                    $rows[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->full_name,
                        'pf_policy_id' => $policy->id,
                        'eligible_from' => $eligibleFrom,
                        'employee_balance' => 0,
                        'employer_balance' => 0,
                        'status' => 'active',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                // The unique employee_id constraint is the final arbiter if two allocation
                // requests race; insertOrIgnore makes retries idempotent rather than failing.
                $inserted = $rows ? PfAccount::query()->insertOrIgnore($rows) : 0;

                return [
                    'created' => $inserted,
                    'skipped' => $skippedExisting + count($rows) - $inserted,
                ];
            }, 3);

            $created += $counts['created'];
            $skipped += $counts['skipped'];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function ledger(int $accountId, int $perPage = 50): LengthAwarePaginator
    {
        return PfLedger::query()->where('pf_account_id', $accountId)->latest('entry_date')->paginate($perPage)->withQueryString();
    }
}
