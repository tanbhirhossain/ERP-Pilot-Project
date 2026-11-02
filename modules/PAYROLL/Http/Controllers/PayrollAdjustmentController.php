<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Services\PayrollAdjustmentService;
use Modules\CORE\Support\PaginatedResource;

final class PayrollAdjustmentController extends Controller
{
    public function index(Request $request, PayrollAdjustmentService $adjustments, EmployeePayrollDataProviderInterface $employees): Response
    {
        $catalog = $adjustments->catalog($request->input('search'));
        $catalog['employee_adjustments'] = PaginatedResource::from($catalog['employee_adjustments']->through(fn ($row) => $row->toArray()));
        return Inertia::render('PAYROLL::Adjustments/Index', $catalog + ['employeeOptions' => $employees->employeeOptions(null, 200)]);
    }

    public function store(Request $request, PayrollAdjustmentService $adjustments): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:global,employee'],
            'adjustment_type' => ['required', 'in:benefit,deduction'],
            'employee_id' => ['required_if:scope,employee', 'nullable', 'exists:employees,employee_id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required_if:scope,global', 'nullable', 'alpha_dash', 'max:40'],
            'calculation_type' => ['required', 'in:fixed,percentage'],
            'amount' => ['required', 'numeric', 'min:0'],
            'percentage_base' => ['nullable', 'in:basic,gross,house_rent,medical'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'auto_apply' => ['nullable', 'boolean'],
            'taxable' => ['nullable', 'boolean'],
            'pf_eligible' => ['nullable', 'boolean'],
        ]);
        $adjustments->create($data, $request->user()?->id);
        return back()->with('success', 'Payroll adjustment saved.');
    }
}
