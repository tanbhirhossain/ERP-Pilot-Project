<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\EmployeeTaxProfile;
use Modules\PAYROLL\Models\EmployeeTaxProfileVersion;
use Modules\PAYROLL\Models\PayrollTaxAudit;
use Modules\PAYROLL\Services\TaxConfigurationService;

final class TaxConfigurationController extends Controller
{
    public function index(Request $request, TaxConfigurationService $tax, EmployeePayrollDataProviderInterface $employees): Response
    {
        return Inertia::render('PAYROLL::Tax/Index', [
            'configuration' => $tax->current(),
            'employeeOptions' => $employees->employeeOptions(null, 200),
            'profiles' => PaginatedResource::from(EmployeeTaxProfile::query()->when($request->input('search'), function ($q) use ($request): void {
                $needle = '%'.addcslashes(trim((string) $request->input('search')), '%_').'%';
                $q->where(fn ($g) => $g->where('employee_id', 'like', $needle)->orWhere('employee_name', 'like', $needle));
            })->latest('updated_at')->paginate($request->integer('per_page', 25))->withQueryString()->through(fn ($row) => $row->toArray())),
            'auditEvents' => PaginatedResource::from(PayrollTaxAudit::query()->with('actor:id,name')->latest('occurred_at')->paginate(min(50, $request->integer('audit_per_page', 20)), ['*'], 'audit_page')->withQueryString()->through(fn ($row) => [
                'id' => $row->id, 'event_type' => $row->event_type, 'employee_id' => $row->employee_id,
                'reason' => $row->change_reason, 'actor' => $row->actor?->name ?? 'System',
                'occurred_at' => $row->occurred_at?->toIso8601String(), 'before' => $row->before_json, 'after' => $row->after_json,
            ])),
        ]);
    }

    public function update(Request $request, TaxConfigurationService $tax): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'mode' => ['required', 'in:fixed,progressive'],
            'fixed_monthly_tax' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'annual_exemption' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'taxable_salary_percent' => ['required', 'numeric', 'between:0,100'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'slabs' => ['nullable', 'array'],
            'slabs.*.lower_limit' => ['required_with:slabs', 'numeric', 'min:0'],
            'slabs.*.upper_limit' => ['nullable', 'numeric', 'min:0'],
            'slabs.*.rate_percent' => ['required_with:slabs', 'numeric', 'between:0,100'],
        ]);
        $tax->update($data, $request->user()?->id, $data['reason']);

        return back()->with('success', 'A new effective-dated tax configuration version and audit record were saved; prior versions and slabs were retained.');
    }

    public function storeProfile(Request $request, EmployeePayrollDataProviderInterface $employees): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'override_mode' => ['required', 'in:global_config,force_fixed,exempt'],
            'fixed_tax_amount' => ['required_if:override_mode,force_fixed', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'fixed_tax_frequency' => ['required', 'in:monthly,annual'],
            'exemption_reason' => ['required_if:override_mode,exempt', 'nullable', 'string', 'min:3', 'max:1000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $effectiveFrom = $data['effective_from'] ?? now()->toDateString();
        $employee = $employees->employeeForPayroll($data['employee_id'], $effectiveFrom, $effectiveFrom);
        if ($employee === null) {
            return back()->withErrors(['employee_id' => 'No effective HRM salary record was found for this employee.']);
        }

        $result = DB::transaction(function () use ($data, $effectiveFrom, $employee, $request): array {
            Employee::query()->where('employee_id', $data['employee_id'])->lockForUpdate()->first();
            $profile = EmployeeTaxProfile::query()->where('employee_id', $data['employee_id'])->lockForUpdate()->first();
            $before = $profile?->toArray();
            $previousVersion = EmployeeTaxProfileVersion::query()->where('employee_id', $data['employee_id'])
                ->orderByDesc('effective_from')->orderByDesc('id')->lockForUpdate()->first();
            if ($previousVersion === null && $profile !== null) {
                $previousVersion = EmployeeTaxProfileVersion::query()->create([
                    'employee_id' => $profile->employee_id,
                    'override_mode' => $profile->override_mode,
                    'fixed_tax_amount' => $profile->fixed_tax_amount,
                    'fixed_tax_frequency' => $profile->fixed_tax_frequency,
                    'exemption_reason' => $profile->exemption_reason,
                    'effective_from' => $profile->effective_from?->toDateString(),
                    'effective_to' => $profile->effective_to?->toDateString(),
                    'change_reason' => 'Legacy employee tax profile preserved before versioned updates.',
                    'created_by' => $profile->updated_by,
                ]);
            }
            if ($previousVersion?->effective_from && $effectiveFrom < $previousVersion->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'Employee tax profile versions cannot be backdated before the latest saved version.']);
            }
            if ($previousVersion !== null && $previousVersion->effective_from?->toDateString() < $effectiveFrom
                && ($previousVersion->effective_to === null || $previousVersion->effective_to->toDateString() >= $effectiveFrom)) {
                $previousVersion->update(['effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString()]);
            }

            $profileData = [
                'employee_name' => $employee->name,
                'override_mode' => $data['override_mode'],
                'fixed_tax_amount' => $data['override_mode'] === 'force_fixed' ? (float) $data['fixed_tax_amount'] : 0,
                'fixed_tax_frequency' => $data['fixed_tax_frequency'],
                'exemption_reason' => $data['override_mode'] === 'exempt' ? $data['exemption_reason'] : null,
                'effective_from' => $effectiveFrom,
                'effective_to' => $data['effective_to'] ?? null,
                'updated_by' => $request->user()?->id,
            ];
            $profile = EmployeeTaxProfile::query()->updateOrCreate(['employee_id' => $data['employee_id']], $profileData);
            $version = EmployeeTaxProfileVersion::query()->create([
                'employee_id' => $data['employee_id'],
                'override_mode' => $data['override_mode'],
                'fixed_tax_amount' => $profileData['fixed_tax_amount'],
                'fixed_tax_frequency' => $data['fixed_tax_frequency'],
                'exemption_reason' => $profileData['exemption_reason'],
                'effective_from' => $effectiveFrom,
                'effective_to' => $data['effective_to'] ?? null,
                'change_reason' => $data['reason'],
                'created_by' => $request->user()?->id,
            ]);
            $after = [
                'profile_id' => $profile->id,
                'version_id' => $version->id,
                'employee_id' => $data['employee_id'],
                'employee_name' => $employee->name,
                ...$version->only(['override_mode', 'fixed_tax_amount', 'fixed_tax_frequency', 'exemption_reason', 'effective_from', 'effective_to', 'change_reason']),
            ];
            PayrollTaxAudit::query()->create([
                'event_type' => 'employee_profile_saved',
                'subject_id' => $version->id,
                'employee_id' => $data['employee_id'],
                'change_reason' => $data['reason'],
                'before_json' => $before,
                'after_json' => $after,
                'actor_id' => $request->user()?->id,
                'occurred_at' => now(),
            ]);

            return ['profile' => $profile, 'version' => $version];
        }, 3);

        return back()->with('success', 'Employee tax profile version #'.$result['version']->id.' and its audit history were saved.');
    }
}
