<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\PaginatedResource;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Services\ProvidentFundService;

final class ProvidentFundController extends Controller
{
    public function index(Request $request, ProvidentFundService $pf, EmployeePayrollDataProviderInterface $employees): Response
    {
        $accountId = $request->integer('account_id') ?: null;
        $selected = $accountId ? PfAccount::query()->with('employee:id,employee_id,full_name')->find($accountId) : null;

        return Inertia::render('PAYROLL::ProvidentFund/Index', [
            'accounts' => PaginatedResource::from($pf->accounts($request->integer('per_page', 25), $request->input('search'))->through(fn ($account) => array_merge($account->toArray(), ['employee_name' => $account->employee?->full_name]))),
            'policies' => $pf->policies(),
            'employeeOptions' => $employees->employeeOptions(null, 200),
            'selectedAccount' => $selected ? array_merge($selected->toArray(), ['employee_name' => $selected->employee?->full_name]) : null,
            'ledger' => $accountId ? $pf->ledger($accountId) : null,
        ]);
    }

    public function allocate(Request $request, ProvidentFundService $pf): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:single,selected,all'],
            'policy_id' => ['required', 'exists:pf_policies,id,is_active,1'],
            'employee_id' => ['required_if:scope,single', 'nullable', 'exists:employees,employee_id'],
            'employee_ids' => ['required_if:scope,selected', 'array', 'min:1', 'max:2000'],
            'employee_ids.*' => ['required', 'distinct', 'exists:employees,employee_id'],
        ]);
        $ids = $data['scope'] === 'single' ? [$data['employee_id']] : ($data['scope'] === 'selected' ? $data['employee_ids'] : []);
        $result = $pf->allocateAccounts((int) $data['policy_id'], $ids, $data['scope'] === 'all');

        return back()->with('success', $result['created'].' PF account(s) allocated; '.$result['skipped'].' existing account(s) left unchanged.');
    }

    public function storePolicy(Request $request, ProvidentFundService $pf): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'employee_contribution_percent' => ['required', 'numeric', 'between:0,100'],
            'employer_contribution_percent' => ['required', 'numeric', 'between:0,100'],
            'minimum_service_months' => ['required', 'integer', 'min:0', 'max:600'],
            'contribution_base' => ['required', 'in:basic,gross,house_rent,pf_eligible'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $pf->savePolicy($data + ['is_active' => true]);

        return back()->with('success', 'Provident Fund policy saved. New payroll calculations will use it.');
    }
}
