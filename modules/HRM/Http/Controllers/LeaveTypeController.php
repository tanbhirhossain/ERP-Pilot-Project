<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\LeaveBalanceService;
use Modules\HRM\Services\LeaveWorkspaceService;

/**
 * Leave Types: the policy behind every leave number.
 *
 * The index used to show four columns — name, code, days per year, paid — while the policy that
 * actually decides how a day is counted (working-day base, accrual, pro-rating, carry, encashment,
 * approval stages) was stored and never shown. This controller assembles both halves, and the policy
 * write path invalidates the balance service's per-request cache so a save is visible immediately.
 */
class LeaveTypeController extends Controller
{
    public function __construct(
        private readonly LeaveWorkspaceService $workspace,
        private readonly LeaveBalanceService $balances,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $r): Response
    {
        $year = (int) ($r->integer('year') ?: now()->format('Y'));

        return Inertia::render('HRM::Leave/Types', [
            'types' => $this->workspace->typeOverview($year),
            'year' => $year,
            'canManage' => (bool) auth()->user()?->can('hrm.leave-types.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::Leave/TypeForm', ['type' => null]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $this->validateType($r);
        $type = LeaveType::create($this->typeAttributes($data));
        $this->savePolicy($type, $data, null);

        return redirect()->route('hrm.leave-types.index')
            ->with('success', "Leave type \"{$type->name}\" created with its policy. Entitlements recalculate from it immediately.");
    }

    public function edit(LeaveType $leave_type): Response
    {
        $setting = LeaveTypeSetting::firstOrCreate(['leave_type_id' => $leave_type->id], []);

        return Inertia::render('HRM::Leave/TypeForm', [
            'type' => [
                'id' => $leave_type->id,
                'name' => $leave_type->name,
                'code' => $leave_type->code,
                'days_per_year' => (float) $leave_type->days_per_year,
                'is_paid' => (bool) $leave_type->is_paid,
                'color' => $leave_type->color,
                'description' => $leave_type->description,
                'is_active' => (bool) $leave_type->is_active,
                'day_count_mode' => $setting->day_count_mode ?: 'calendar_days',
                'accrual_mode' => $setting->accrual_mode ?: 'annual',
                'proration_mode' => $setting->proration_mode ?: 'none',
                'working_days' => is_array($setting->working_days) && $setting->working_days !== [] ? array_map('intval', $setting->working_days) : LeaveBalanceService::DEFAULT_WORKING_DAYS,
                'approval_steps' => is_array($setting->approval_steps) && $setting->approval_steps !== [] ? $setting->approval_steps : ['HR'],
                'is_carry_forward' => (bool) $setting->is_carry_forward,
                'max_carry_days' => $setting->max_carry_days === null ? null : (float) $setting->max_carry_days,
                'carry_expiry_months' => (int) ($setting->carry_expiry_months ?? 3),
                'is_encashable' => (bool) $setting->is_encashable,
                'encash_max_days' => $setting->encash_max_days === null ? null : (float) $setting->encash_max_days,
                'encash_salary_component' => $setting->encash_salary_component ?? 'basic',
                'live_requests' => (int) LeaveRequest::query()->where('leave_type_id', $leave_type->id)->whereIn('status', ['Pending', 'Approved'])->count(),
            ],
            'preview' => $this->policyPreview($leave_type, $setting),
        ]);
    }

    public function update(Request $r, LeaveType $leave_type): RedirectResponse
    {
        $data = $this->validateType($r);
        $leave_type->update($this->typeAttributes($data));
        $this->savePolicy($leave_type, $data, $leave_type->id);

        return redirect()->route('hrm.leave-types.index')
            ->with('success', "Leave type \"{$leave_type->name}\" updated. Balances recalculated with the new policy.");
    }

    public function destroy(LeaveType $leave_type): RedirectResponse
    {
        if (LeaveRequest::query()->where('leave_type_id', $leave_type->id)->exists()) {
            return back()->with('error', "{$leave_type->name} has applications against it. Deactivate it instead of deleting, so history stays readable.");
        }

        $name = $leave_type->name;
        $leave_type->delete();

        return back()->with('success', "Leave type \"{$name}\" deleted.");
    }

    /**
     * What the policy currently in the form would mean for one employee — a sanity check a policy
     * screen should offer but this one never did. Shown in the form as two worked examples.
     */
    private function policyPreview(LeaveType $type, LeaveTypeSetting $setting): array
    {
        $employee = $this->employees->options(null, 1)[0]['value'] ?? null;
        if ($employee === null) {
            return ['employee' => null];
        }

        $balance = $this->balances->balance($employee, $type->id, (int) now()->format('Y'));

        return [
            'employee' => $employee,
            'balance' => $balance,
        ];
    }

    private function validateType(Request $r): array
    {
        return $r->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['required', 'boolean'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'day_count_mode' => ['required', 'in:calendar_days,working_days'],
            'accrual_mode' => ['required', 'in:annual,monthly'],
            'proration_mode' => ['required', 'in:none,monthly,daily'],
            'working_days' => ['nullable', 'array'],
            'working_days.*' => ['integer', 'between:0,6'],
            'approval_steps' => ['nullable', 'array'],
            'approval_steps.*' => ['string', 'max:50'],
            'is_carry_forward' => ['required', 'boolean'],
            'max_carry_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'carry_expiry_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'is_encashable' => ['required', 'boolean'],
            'encash_max_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'encash_salary_component' => ['required', 'in:basic,gross'],
        ]);
    }

    private function typeAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'days_per_year' => $data['days_per_year'],
            'is_paid' => (bool) $data['is_paid'],
            'color' => $data['color'] ?? '#6366f1',
            'description' => $data['description'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ];
    }

    private function savePolicy(LeaveType $type, array $data, ?int $invalidTypeId): void
    {
        $workingDays = collect($data['working_days'] ?? LeaveBalanceService::DEFAULT_WORKING_DAYS)
            ->map(fn ($d): int => (int) $d)->unique()->sort()->values()->all();
        $steps = collect($data['approval_steps'] ?? ['HR'])->map(fn ($s): string => trim((string) $s))->filter()->values()->all();

        LeaveTypeSetting::updateOrCreate(['leave_type_id' => $type->id], [
            'day_count_mode' => $data['day_count_mode'],
            'accrual_mode' => $data['accrual_mode'],
            'proration_mode' => $data['proration_mode'],
            'working_days' => $workingDays === [] ? LeaveBalanceService::DEFAULT_WORKING_DAYS : $workingDays,
            'approval_steps' => $steps === [] ? ['HR'] : $steps,
            'is_carry_forward' => (bool) $data['is_carry_forward'],
            'max_carry_days' => $data['is_carry_forward'] ? ($data['max_carry_days'] ?? null) : null,
            'carry_expiry_months' => $data['carry_expiry_months'] ?? 3,
            'is_encashable' => (bool) $data['is_encashable'],
            'encash_max_days' => $data['is_encashable'] ? ($data['encash_max_days'] ?? null) : null,
            'encash_salary_component' => $data['encash_salary_component'],
        ]);

        $this->balances->forgetPolicy($invalidTypeId ?? $type->id);
    }
}
