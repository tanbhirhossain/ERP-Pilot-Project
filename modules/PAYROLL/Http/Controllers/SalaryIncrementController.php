<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\SalaryIncrement;
use Modules\PAYROLL\Services\SalaryIncrementService;

final class SalaryIncrementController extends Controller
{
    public function index(Request $request, SalaryIncrementService $increments): Response
    {
        $status = $request->input('filters.status');
        if (!is_string($status) || !in_array($status, ['pending', 'approved', 'applied', 'rejected'], true)) $status = null;
        $search = $request->input('search');
        if (!is_string($search)) $search = null;

        return Inertia::render('PAYROLL::Increments/Index', [
            'canApprove' => $request->user()?->can('payroll.increments.approve') ?? false,
            'increments' => PaginatedResource::from(
                $increments->paginate($request->integer('per_page', 25), $search, $status)
                    ->through(fn (SalaryIncrement $row) => $row->toArray()),
            ),
        ]);
    }

    public function create(EmployeePayrollDataProviderInterface $employees): Response
    {
        return Inertia::render('PAYROLL::Increments/Create', [
            'employeeOptions' => $employees->employeeOptions(null, 200),
        ]);
    }

    public function store(Request $request, SalaryIncrementService $increments): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:single,selected,all'],
            'employee_id' => ['required_if:scope,single', 'nullable', 'exists:employees,employee_id'],
            'employee_ids' => ['required_if:scope,selected', 'array', 'min:1', 'max:2000'],
            'employee_ids.*' => ['required', 'distinct', 'exists:employees,employee_id'],
            'increment_type' => ['required', 'in:fixed,percentage'],
            'increment_value' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if ($data['increment_type'] === 'percentage' && (float) $data['increment_value'] > 1000) {
            throw ValidationException::withMessages(['increment_value' => 'Percentage increases cannot exceed 1,000%.']);
        }

        $employeeIds = match ($data['scope']) {
            'single' => [$data['employee_id']],
            'selected' => $data['employee_ids'],
            default => Employee::query()->where('is_active', true)->whereIn('status', ['Active', 'On Leave'])->orderBy('employee_id')->pluck('employee_id')->all(),
        };
        if (!$employeeIds) return back()->with('error', 'No payroll-eligible employees were found for this increment.');

        $result = $increments->createForEmployees($data, $employeeIds, $request->user()?->id);
        if ($result['created']->isEmpty()) {
            $message = $result['duplicates'] > 0
                ? 'No requests were created: a pending or applied increment already exists for the selected employee/date.'
                : 'No requests were created because the selected employees have no eligible salary record on the effective date.';
            return back()->with('error', $message);
        }

        $message = $result['created']->count().' salary increment request(s) submitted for approval.';
        if ($result['skipped'] > 0) $message .= ' '.$result['skipped'].' employee(s) skipped because no eligible effective salary record was found.';
        if ($result['duplicates'] > 0) $message .= ' '.$result['duplicates'].' duplicate employee/date request(s) skipped.';
        return redirect()->route('payroll.increments.index')->with('success', $message);
    }

    public function approve(Request $request, SalaryIncrement $increment, SalaryIncrementService $increments): RedirectResponse
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:1000']]);
        $increments->approve($increment, (int) $request->user()->id, $data['comments'] ?? null);
        return back()->with('success', 'Increment approved and recorded in HRM salary history.');
    }

    public function reject(Request $request, SalaryIncrement $increment, SalaryIncrementService $increments): RedirectResponse
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:1000']]);
        $increments->reject($increment, (int) $request->user()->id, $data['comments'] ?? null);
        return back()->with('success', 'Increment request rejected with a decision audit record.');
    }
}
