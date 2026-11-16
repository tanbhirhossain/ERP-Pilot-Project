<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Leave\HrmLeaveBalance;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;

/**
 * Leave arithmetic — the single place that answers "how many days is this, and what is left".
 *
 * Three defects in the previous version made every balance on every screen wrong:
 *
 *  1. `available` was `entitlement - approved - pending`. The carry-forward that year-end wrote into
 *     `hrm_leave_balances` was read by nothing, so carrying leave forward did not carry anything.
 *  2. Entitlement always assumed a full year of service. 219 of this hospital's staff joined during
 *     2026 and each of them was shown the full 40-day grant.
 *  3. Pro-rating divided by the calendar year but multiplied by days *elapsed*, so the same employee's
 *     entitlement silently grew through the year and never reached the full grant on 31 December.
 *
 * Entitlement is now computed once for the requested year (as of 31 December, not "today"), carry-forward
 * is part of the balance, and a frozen year-end snapshot wins over live recomputation when one exists.
 */
final class LeaveBalanceService
{
    /** Weekdays (0=Sun … 6=Sat). Bangladesh's weekend is Fri+Sat; 0–4 (Sun–Thu) is also common in offices. */
    public const DEFAULT_WORKING_DAYS = [0, 1, 2, 3, 4];

    private array $settingsCache = [];

    /** @var array<string, array<string, mixed>> year-end snapshots loaded this request, keyed employee|type|year */
    private array $snapshotCache = [];

    public function settings(LeaveType $type): array
    {
        if (isset($this->settingsCache[$type->id])) {
            return $this->settingsCache[$type->id];
        }
        $setting = LeaveTypeSetting::firstOrCreate(['leave_type_id' => $type->id], []);

        return $this->settingsCache[$type->id] = [
            'day_count_mode' => $setting->day_count_mode ?: 'calendar_days',
            'accrual_mode' => $setting->accrual_mode ?: 'annual',
            'proration_mode' => $setting->proration_mode ?: 'none',
            'working_days' => is_array($setting->working_days) && $setting->working_days !== []
                ? array_map('intval', $setting->working_days)
                : self::DEFAULT_WORKING_DAYS,
            'approval_steps' => is_array($setting->approval_steps) && $setting->approval_steps !== []
                ? $setting->approval_steps
                : ['HR'],
            'is_carry_forward' => (bool) $setting->is_carry_forward,
            'max_carry_days' => $setting->max_carry_days === null ? null : (float) $setting->max_carry_days,
            'carry_expiry_months' => (int) ($setting->carry_expiry_months ?? 3),
            'is_encashable' => (bool) $setting->is_encashable,
            'encash_max_days' => $setting->encash_max_days === null ? null : (float) $setting->encash_max_days,
            'encash_salary_component' => $setting->encash_salary_component ?: 'basic',
            'gender_eligibility' => $setting->gender_eligibility ?: null,
        ];
    }

    /** Drop the per-instance policy cache — call after writing policy inside a request. */
    public function forgetPolicy(?int $typeId = null): void
    {
        if ($typeId === null) {
            $this->settingsCache = [];
        } else {
            unset($this->settingsCache[$typeId]);
        }
    }

    /**
     * Count the chargeable days a leave application covers.
     *
     * `calendar_days` counts every date in the range. `working_days` counts only dates that fall on the
     * type's working weekdays and are not a holiday (global, or for this employee's department).
     */
    public function days(
        string $employeeId,
        int $typeId,
        string $from,
        string $to,
        bool $half = false,
        ?LeaveType $loadedType = null,
        ?Employee $loadedEmployee = null,
        ?array $loadedHolidays = null,
    ): float {
        $type = $loadedType ?? LeaveType::findOrFail($typeId);
        $settings = $this->settings($type);
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            // A one-day application stores its single date in both fields; a caller that passes only a
            // start date is asking about that day, not about an inverted range.
            if ($from === $to || $to === '') {
                $end = $start->copy();
            } else {
                throw ValidationException::withMessages(['to_date' => 'The end date must be on or after the start date.']);
            }
        }
        if ($start->year !== $end->year) {
            throw ValidationException::withMessages(['to_date' => 'A leave application must be within one calendar year. Submit separate applications for each year.']);
        }
        if ($half && ! $start->isSameDay($end)) {
            throw ValidationException::withMessages(['is_half_day' => 'Half-day leave must be for a single date.']);
        }

        if ($settings['day_count_mode'] === 'calendar_days') {
            return $half ? 0.5 : (float) ($start->diffInDays($end) + 1);
        }

        $holidays = $loadedHolidays ?? $this->holidaysFor($employeeId, $start->toDateString(), $end->toDateString(), $loadedEmployee);
        $days = 0;
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (in_array($date->dayOfWeek, $settings['working_days'], true) && ! in_array($date->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return $half ? ($days > 0 ? 0.5 : 0.0) : (float) $days;
    }

    /** Holiday dates (ISO) inside a range that apply to this employee: global plus their department's. */
    public function holidaysFor(string $employeeId, string $from, string $to, ?Employee $loadedEmployee = null): array
    {
        $employee = $loadedEmployee ?? Employee::with('department')->where('employee_id', $employeeId)->first();

        return Holiday::query()
            ->whereBetween('holiday_date', [$from, $to])
            ->where(function ($q) use ($employee) {
                $q->whereNull('department_id');
                if ($employee?->department_id) {
                    $q->orWhere('department_id', $employee->department_id);
                }
            })
            ->get(['holiday_date'])
            ->map(fn ($row): string => Carbon::parse($row->holiday_date)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Entitlement for one employee, one type, one year.
     *
     * Computed as of the end of the requested year rather than "today", so a February report and a
     * December report agree and the year always closes on the full grant. The only thing that reduces
     * it is service: somebody who joined in September is entitled to September–December.
     */
    public function entitlement(string $employeeId, LeaveType $type, int $year): float
    {
        $settings = $this->settings($type);
        $annual = (float) $type->days_per_year;

        $employee = Employee::query()->where('employee_id', $employeeId)->first(['employee_id', 'joining_date', 'gender']);

        // A type reserved for one gender is nobody else's entitlement. Maternity (180 days) and
        // paternity (7 days) were granted to all 998 employees, so the register reported 187 days of
        // leave that most of the payroll could never take — arithmetically right, practically useless.
        //
        // An unset restriction means everyone, and so does an employee whose gender is not recorded:
        // the register must not invent a restriction out of missing data. The consequence is that a
        // gender-restricted type still credits employees with no gender on file, which is why the
        // screen has to say how many of those there are rather than quietly resolving it.
        $restriction = $settings['gender_eligibility'] ?? null;
        if ($restriction !== null && $restriction !== 'All') {
            $gender = $employee?->gender;
            if ($gender !== null && $gender !== '' && strcasecmp((string) $gender, (string) $restriction) !== 0) {
                return 0.0;
            }
        }

        if ($settings['accrual_mode'] !== 'monthly' && $settings['proration_mode'] === 'none') {
            return round($annual, 2);
        }
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();

        // Somebody who has not started by the end of this leave year has no entitlement in it.
        if ($employee?->joining_date !== null) {
            $joined = Carbon::parse($employee->joining_date)->startOfDay();
            if ($joined->gt($yearEnd)) {
                return 0.0;
            }
            $activeStart = $joined->max($yearStart);
        } else {
            $activeStart = $yearStart;
        }

        $months = (($yearEnd->year - $activeStart->year) * 12) + ($yearEnd->month - $activeStart->month) + 1;

        if ($settings['proration_mode'] === 'daily') {
            $activeDays = $activeStart->diffInDays($yearEnd) + 1;
            $yearDays = $yearStart->isLeapYear() ? 366 : 365;

            return round($annual * min($activeDays, $yearDays) / $yearDays, 2);
        }

        // 'monthly' proration and/or monthly accrual both resolve to whole months of service in the year.
        return round($annual * min(12, max(0, $months)) / 12, 2);
    }

    /**
     * The balance as the screens should show it.
     *
     * A closed year-end snapshot is authoritative: once a year is closed its figures are history and must
     * not move because somebody back-dated an approval. Open years are computed live, but always include
     * the carry-forward the previous year handed over.
     */
    public function balance(string $employeeId, int $typeId, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');
        $type = LeaveType::findOrFail($typeId);
        $settings = $this->settings($type);

        $snapshot = $this->snapshot($employeeId, $typeId, $year);
        if ($snapshot !== null && $snapshot->is_closed) {
            return [
                'leave_type_id' => $typeId,
                'leave_type' => $type->name,
                'code' => $type->code,
                'color' => $type->color,
                'is_paid' => (bool) $type->is_paid,
                'year' => $year,
                'opening' => round((float) $snapshot->opening_balance, 2),
                'entitlement' => round((float) $snapshot->entitlement, 2),
                'carry_forward' => round((float) $snapshot->carry_forward, 2),
                'approved' => round((float) $snapshot->availed, 2),
                'pending' => round((float) $snapshot->pending, 2),
                'encashed' => round((float) $snapshot->encashed, 2),
                'lapsed' => round((float) $snapshot->lapsed, 2),
                'available' => round((float) $snapshot->available, 2),
                'available_raw' => round((float) $snapshot->available, 2),
                'total' => round((float) $snapshot->opening_balance + (float) $snapshot->entitlement + (float) $snapshot->carry_forward, 2),
                'frozen' => true,
                'closed_at' => $snapshot->closed_at?->toDateString(),
                'day_count_mode' => $settings['day_count_mode'],
                'accrual_mode' => $settings['accrual_mode'],
                'proration_mode' => $settings['proration_mode'],
                'approval_steps' => $settings['approval_steps'],
            ];
        }

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $requests = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $typeId)
            ->whereBetween('from_date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->whereIn('status', ['Approved', 'Pending'])
            ->get(['days', 'status']);

        $approved = round((float) $requests->where('status', 'Approved')->sum('days'), 2);
        $pending = round((float) $requests->where('status', 'Pending')->sum('days'), 2);

        // An open snapshot may still hold the carry the previous year handed over; live carry is 0.
        $opening = $snapshot !== null ? round((float) $snapshot->opening_balance, 2) : 0.0;
        $carry = $snapshot !== null ? round((float) $snapshot->carry_forward, 2) : $this->liveCarryForward($employeeId, $typeId, $year);
        $entitlement = $this->entitlement($employeeId, $type, $year);
        $total = round($opening + $entitlement + $carry, 2);

        return [
            'leave_type_id' => $typeId,
            'leave_type' => $type->name,
            'code' => $type->code,
            'color' => $type->color,
            'is_paid' => (bool) $type->is_paid,
            'year' => $year,
            'opening' => $opening,
            'entitlement' => $entitlement,
            'carry_forward' => $carry,
            'approved' => $approved,
            'pending' => $pending,
            'encashed' => $snapshot !== null ? round((float) $snapshot->encashed, 2) : 0.0,
            'lapsed' => 0.0,
            /*
             * Two numbers, deliberately. `available_raw` is the arithmetic result and can be negative —
             * it is what "do they actually have the days?" must be asked of. `available` is clamped at
             * zero because a balance register should not print −3 days beside a person's name. Reading
             * the clamped one in an assertion is how an over-drawn approval passed validation.
             */
            'available_raw' => round($total - $approved - $pending, 2),
            'available' => round(max(0, $total - $approved - $pending), 2),
            'total' => $total,
            'frozen' => false,
            'closed_at' => null,
            'day_count_mode' => $settings['day_count_mode'],
            'accrual_mode' => $settings['accrual_mode'],
            'proration_mode' => $settings['proration_mode'],
            'approval_steps' => $settings['approval_steps'],
        ];
    }

    /** Same as balance(), for every active leave type at once. Ordered by the type's display order. */
    public function balanceForAllTypes(string $employeeId, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');

        return LeaveType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type): array => $this->balance($employeeId, $type->id, $year))
            ->all();
    }

    /**
     * Guard an application (or an approval) against the balance it is drawn on.
     *
     * The previous version only ran this on create, so an approval could push a balance below zero
     * whenever the rules had changed between applying and approving.
     */
    public function assertAvailable(string $employeeId, int $typeId, string $from, string $to, bool $half = false, ?int $ignoreId = null): float
    {
        $requested = $this->days($employeeId, $typeId, $from, $to, $half);
        $year = (int) Carbon::parse($from)->year;
        $balance = $this->balance($employeeId, $typeId, $year);

        // The unclamped figure: with the clamped one, a negative balance read as zero and the days of
        // the application being edited were added back on top of it, so an overdraw passed.
        $available = (float) ($balance['available_raw'] ?? $balance['available']);
        if ($ignoreId) {
            $old = LeaveRequest::find($ignoreId);
            if ($old && $old->status === 'Pending' && $old->employee_id === $employeeId && (int) $old->leave_type_id === $typeId) {
                $available += (float) $old->days;
            }
        }
        if ($requested > $available) {
            throw ValidationException::withMessages(['leave_type_id' => "Insufficient leave balance: {$available} day(s) available, {$requested} day(s) requested."]);
        }

        return $requested;
    }

    /** Carry-forward handed over from the previous closed year, for a year that has not been closed yet. */
    private function liveCarryForward(string $employeeId, int $typeId, int $year): float
    {
        $prev = $this->snapshot($employeeId, $typeId, $year - 1);
        if ($prev === null || ! $prev->is_closed) {
            return 0.0;
        }

        return round((float) $prev->carry_forward, 2);
    }

    private function snapshot(string $employeeId, int $typeId, int $year): ?HrmLeaveBalance
    {
        $key = "{$employeeId}|{$typeId}|{$year}";
        if (array_key_exists($key, $this->snapshotCache)) {
            return $this->snapshotCache[$key];
        }

        return $this->snapshotCache[$key] = HrmLeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $typeId)
            ->where('year', $year)
            ->first();
    }

    /** Drop the snapshot cache — after a year-end close, the frozen rows are new. */
    public function forgetSnapshots(): void
    {
        $this->snapshotCache = [];
    }

    /**
     * How many chargeable dates a range covers, and which of them are not chargeable.
     *
     * The application form shows this so an employee can see that Sat 7 Nov costs them nothing before
     * they submit, instead of finding out from the balance afterwards.
     */
    public function previewDays(string $employeeId, int $typeId, string $from, string $to, bool $half = false): array
    {
        $type = LeaveType::findOrFail($typeId);
        $settings = $this->settings($type);
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $holidays = $this->holidaysFor($employeeId, $start->toDateString(), $end->toDateString());

        $dates = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $iso = $date->toDateString();
            $excluded = null;
            if ($settings['day_count_mode'] === 'working_days') {
                if (! in_array($date->dayOfWeek, $settings['working_days'], true)) {
                    $excluded = 'weekend';
                } elseif (in_array($iso, $holidays, true)) {
                    $excluded = 'holiday';
                }
            }
            $dates[] = ['date' => $iso, 'weekday' => $date->dayOfWeek, 'excluded' => $excluded];
        }

        $chargeable = count(array_filter($dates, fn (array $d): bool => $d['excluded'] === null));
        $days = $settings['day_count_mode'] === 'calendar_days'
            ? ($half ? 0.5 : (float) ($start->diffInDays($end) + 1))
            : ($half ? ($chargeable > 0 ? 0.5 : 0.0) : (float) $chargeable);

        return [
            'days' => $days,
            'chargeable' => $chargeable,
            'excluded' => count($dates) - $chargeable,
            'dates' => $dates,
            'day_count_mode' => $settings['day_count_mode'],
            'working_days' => $settings['working_days'],
            'holidays' => $holidays,
        ];
    }

    /**
     * Entitlement days longer than this are treated as long-leave (maternity, sabbatical) and are
     * reported separately from the ordinary entitlement an employee can actually spend in a year.
     *
     * A register that adds 180 days of maternity to 47 days of casual, earned, sick and paternity leave
     * reports 227 days available for every employee on the payroll — including the men, and including
     * every department that will never use it. The number is arithmetically right and practically useless.
     */
    public const LONG_LEAVE_DAYS = 90;

    /** Sum of the liability the balance register carries: entitlement + carry − approved − pending, per type. */
    public function register(int $year, ?string $search = null, ?int $typeId = null, int $perPage = 25, int $page = 1): array
    {
        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get();
        $query = Employee::query()
            ->where('is_active', true)
            ->select(['employee_id', 'full_name', 'first_name', 'last_name', 'department_id', 'designation_id', 'joining_date'])
            ->with(['department:id,name', 'designation:id,name']);

        if ($search !== null && trim($search) !== '') {
            $needle = '%'.addcslashes(trim($search), '%_').'%';
            $query->where(fn ($q) => $q->where('employee_id', 'like', $needle)
                ->orWhere('full_name', 'like', $needle)
                ->orWhere('first_name', 'like', $needle)
                ->orWhere('last_name', 'like', $needle));
        }

        $total = (clone $query)->count();
        $employees = $query->orderBy('employee_id')->forPage($page, $perPage)->get();

        // Types an employee can realistically spend in a working year, versus long-leave entitlements.
        $longTypeIds = $types->filter(fn (LeaveType $t): bool => (float) $t->days_per_year >= self::LONG_LEAVE_DAYS)->pluck('id')->all();

        $rows = $employees->map(function (Employee $employee) use ($types, $year, $typeId, $longTypeIds): array {
            $balances = collect($typeId !== null
                ? [$this->balance($employee->employee_id, $typeId, $year)]
                : $this->balanceForAllTypes($employee->employee_id, $year));

            $available = $balances->sum('available');
            $used = $balances->sum('approved');
            $pending = $balances->sum('pending');

            // The headline figure: days this person can actually use this year, without long leave.
            $coreAvailable = $balances
                ->reject(fn (array $b): bool => in_array((int) $b['leave_type_id'], $longTypeIds, true))
                ->sum('available');

            return [
                'employee_id' => $employee->employee_id,
                'employee' => $employee->full_name ?: trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'years_of_service' => $employee->joining_date ? Carbon::parse($employee->joining_date)->diffInYears(now()) : null,
                'available' => round((float) $available, 2),
                'core_available' => round((float) $coreAvailable, 2),
                'long_leave_available' => round((float) $available - (float) $coreAvailable, 2),
                'used' => round((float) $used, 2),
                'pending' => round((float) $pending, 2),
                'by_type' => $balances->keyBy('leave_type_id')->map(fn (array $b): array => [
                    'available' => $b['available'],
                    'approved' => $b['approved'],
                    'entitlement' => $b['entitlement'],
                ])->all(),
            ];
        })->all();

        return [
            'rows' => $rows,
            'types' => $types->map(fn (LeaveType $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'color' => $t->color,
                'is_long_leave' => (float) $t->days_per_year >= self::LONG_LEAVE_DAYS,
                'days_per_year' => (float) $t->days_per_year,
            ])->all(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))],
            'year' => $year,
        ];
    }
}
