<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\PaginatedResource;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollSettlement;
use Modules\PAYROLL\Services\PayrollSettlementService;

final class PayrollSettlementController extends Controller
{
    public function index(Request $request, PayrollSettlementService $settlements): Response
    {
        return Inertia::render('PAYROLL::Settlements/Index', ['settlements' => PaginatedResource::from($settlements->paginate($request->integer('per_page', 25), $request->input('search'))->through(fn ($row) => $row->toArray())), 'gratuityPolicies' => $settlements->gratuityPolicies()]);
    }

    public function create(EmployeePayrollDataProviderInterface $employees): Response
    {
        return Inertia::render('PAYROLL::Settlements/Create', ['employeeOptions' => $employees->employeeOptions(null, 200)]);
    }

    public function store(Request $request, PayrollSettlementService $settlements): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'settlement_date' => ['required', 'date'],
            'last_working_date' => ['required', 'date', 'before_or_equal:settlement_date'],
            'leave_encashment' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'other_benefits' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'leave_encashment_reason' => ['nullable', 'string', 'max:500'],
            'other_benefits_reason' => ['nullable', 'string', 'max:500'],
            'deductions_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $settlements->create($data, $request->user()?->id);

        return redirect()->route('payroll.settlements.index')->with('success', 'Final settlement calculated and submitted for approval.');
    }

    public function storeGratuityPolicy(Request $request, PayrollSettlementService $settlements): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'minimum_service_years' => ['required', 'integer', 'min:0', 'max:60'],
            'days_per_service_year' => ['required', 'numeric', 'min:0', 'max:366'],
            'calculation_base' => ['required', 'in:basic,gross'],
        ]);
        $settlements->saveGratuityPolicy($data + ['is_active' => true]);

        return back()->with('success', 'Gratuity policy saved.');
    }

    public function approve(Request $request, PayrollSettlement $settlement, PayrollSettlementService $settlements): RedirectResponse
    {
        // The service refuses anything that is not pending, and refuses to pay anything that is not
        // approved. Those are the two ordinary mistakes a payroll clerk makes on this screen (double
        // click, or jumping the approval step), and uncaught they render a 500 page with no message.
        // Every sibling controller converts the same refusal into a flash; this one now does too.
        try {
            $settlements->approve($settlement, (int) $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['settlement' => $e->getMessage()]);
        }

        return back()->with('success', 'Final settlement approved.');
    }

    public function paid(Request $request, PayrollSettlement $settlement, PayrollSettlementService $settlements): RedirectResponse
    {
        try {
            $settlements->markPaid($settlement, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['settlement' => $e->getMessage()]);
        }

        return back()->with('success', 'Settlement marked paid.');
    }
}
