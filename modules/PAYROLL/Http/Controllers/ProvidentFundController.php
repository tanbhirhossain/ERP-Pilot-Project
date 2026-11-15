<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfWithdrawal;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Services\ProvidentFundService;

/**
 * The provident-fund module.
 *
 * Five screens, because a fund is five questions asked by five different people:
 *
 *   1. **Overview** — what does the fund owe, what does it hold, and is anything waiting on me?
 *   2. **Contribution register** — what happened each payroll month, and can I accrue or settle it?
 *   3. **Members** — who is in the fund and what does each hold?
 *   4. **Member statement** — one person's passbook, entry by entry.
 *   5. **Withdrawals** — what is leaving the fund, and has the money actually moved?
 *
 * They were one 31-line page before, which is why the fund looked broken: there was nowhere for the
 * numbers to be seen even once they were correct. Cramming them back onto one screen would repeat
 * that mistake in the opposite direction — a fund desk is a place to work, not a poster.
 */
final class ProvidentFundController extends Controller
{
    /** The fund desk: four figures, the trend, and what needs a decision. */
    public function dashboard(Request $request, ProvidentFundService $pf): Response
    {
        return Inertia::render('PAYROLL::ProvidentFund/Dashboard', array_merge($pf->dashboard(12), [
            'canManage' => (bool) $request->user()?->can('payroll.pf.manage'),
        ]));
    }

    /** The contribution register: one line per payroll month, plus its accrual and settlement state. */
    public function index(Request $request, ProvidentFundService $pf): Response
    {
        return Inertia::render('PAYROLL::ProvidentFund/Index', [
            'summary' => $pf->fundSummary(),
            'cash' => $pf->cashPosition(),
            'periods' => $pf->accrualCandidates(24),
            'activity' => $pf->monthlyActivity(24),
            'canManage' => (bool) $request->user()?->can('payroll.pf.manage'),
        ]);
    }

    /** The member register: every account, what it holds, and how to enrol the rest. */
    public function members(Request $request, ProvidentFundService $pf, EmployeePayrollDataProviderInterface $employees): Response
    {
        $dt = DataTableQuery::fromRequest(
            $request,
            ['employee_id', 'employee_name', 'eligible_from', 'total_balance'],
            ['status', 'policy_id', 'balance', 'date_from', 'date_to'],
            defaultSort: 'employee_name',
            defaultDirection: 'asc',
        );

        return Inertia::render('PAYROLL::ProvidentFund/Members', [
            'accounts' => PaginatedResource::from($pf->accounts($dt)),
            'summary' => $pf->fundSummary(),
            'policies' => $pf->policies(),
            'employeeOptions' => $employees->employeeOptions(null, 200),
            'canManage' => (bool) $request->user()?->can('payroll.pf.manage'),
            'filterDefs' => [
                [
                    'key' => 'status',
                    'label' => 'Status',
                    'kind' => 'select',
                    'options' => [
                        ['value' => 'active', 'label' => 'Active'],
                        ['value' => 'suspended', 'label' => 'Suspended'],
                        ['value' => 'closed', 'label' => 'Closed'],
                    ],
                ],
                [
                    'key' => 'balance',
                    'label' => 'Balance',
                    'kind' => 'select',
                    'options' => [
                        ['value' => 'positive', 'label' => 'Has a balance'],
                        ['value' => 'zero', 'label' => 'No contributions yet'],
                    ],
                ],
            ],
        ]);
    }

    /** One member's statement: every movement, with the running balance the member is owed. */
    public function show(Request $request, int $account, ProvidentFundService $pf): Response
    {
        $model = $pf->findAccount($account);

        return Inertia::render('PAYROLL::ProvidentFund/Show', [
            // Dates leave the server as `Y-m-d`. A `date` cast serialises to an ISO timestamp whose
            // zone the browser reinterprets, which is how "eligible from" ended up rendering as
            // "2024-02-19T06:00:00Z" on a Dhaka machine for a row the database says is the 18th.
            'account' => array_merge($model->toArray(), [
                'total_balance' => $model->totalBalance(),
                'eligible_from' => $model->eligible_from?->toDateString(),
                'employee' => $model->employee === null ? null : array_merge($model->employee->toArray(), [
                    'joining_date' => $model->employee->joining_date === null
                        ? null
                        : Carbon::parse($model->employee->joining_date)->toDateString(),
                ]),
            ]),
            'statement' => $pf->statement(
                $model->id,
                $request->string('from')->toString() ?: null,
                $request->string('to')->toString() ?: null,
            ),
            'withdrawals' => PfWithdrawal::query()
                ->where('pf_account_id', $model->id)
                ->latest('id')->limit(20)->get(),
            'canManage' => (bool) $request->user()?->can('payroll.pf.manage'),
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

        $ids = match ($data['scope']) {
            'single' => [$data['employee_id']],
            'selected' => $data['employee_ids'],
            default => [],
        };

        $result = $pf->allocateAccounts((int) $data['policy_id'], $ids, $data['scope'] === 'all');

        // Say what was actually looked at, not only what changed: "0 created" with no denominator is
        // why "one click allocation" read as broken when it had simply already been done. The
        // denominator is the scope we resolved here — the service reports only what it did.
        $examined = $data['scope'] === 'all'
            ? $result['created'] + $result['skipped']
            : count($ids);

        $message = sprintf(
            '%d new account(s) created from %d employee(s) examined; %d already had one.',
            $result['created'],
            $examined,
            $result['skipped'],
        );

        return back()->with($result['created'] > 0 ? 'success' : 'info', $message);
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

        $pf->savePolicy($data);

        return back()->with('success', 'Provident Fund policy saved. New payroll calculations will use it.');
    }

    /**
     * Accrue one closed payroll month.
     *
     * The month must have a salary sheet: contributions belong to a document, and posting a month
     * that was never processed would invent a liability out of nothing.
     */
    public function accrue(Request $request, ProvidentFundService $pf): RedirectResponse
    {
        $data = $request->validate([
            'pay_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'pay_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $sheet = PayrollSalarySheet::query()
            ->where('pay_year', $data['pay_year'])
            ->where('pay_month', $data['pay_month'])
            ->first();

        if ($sheet === null) {
            return back()->with('error', sprintf(
                'There is no salary sheet for %04d-%02d. Run payroll for that month before accruing its provident fund.',
                $data['pay_year'],
                $data['pay_month'],
            ));
        }

        try {
            $result = $pf->accrueFromSheet($sheet, (int) $request->user()->id);
        } catch (ValidationException $e) {
            // The service gate is the authority; the screen must explain it, not swallow it.
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with(
            $result['posted'] > 0 ? 'success' : 'info',
            sprintf(
                '%s: %d slip(s) accrued, %d skipped (no eligible account, or the month was already posted).',
                $result['period'],
                $result['posted'],
                $result['skipped'],
            ),
        );
    }

    /** Backfill every month already computed, so the fund starts correct rather than merely correct later. */
    public function backfill(Request $request, ProvidentFundService $pf): RedirectResponse
    {
        abort_unless($request->user()?->can('payroll.pf.manage'), 403);

        $result = $pf->backfillHistory((int) $request->user()->id);

        $months = collect($result['months'])
            ->map(fn ($count, $period) => $period.' ('.$count.')')
            ->implode(', ');

        return back()->with($result['posted'] > 0 ? 'success' : 'info', sprintf(
            '%d contribution line(s) posted across %d month(s); %d slip(s) had nothing to post.%s',
            $result['posted'],
            count($result['months']),
            $result['skipped'],
            $months !== '' ? ' Periods: '.$months.'.' : '',
        ));
    }

    public function settle(Request $request, ProvidentFundService $pf): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $result = $pf->settleMonth($data['period'], (int) $request->user()->id);

        return back()->with($result['settled'] > 0 ? 'success' : 'info', sprintf(
            '%s: %d accrued line(s) marked settled (%s). The liability does not change — only the cash side does.',
            $result['period'],
            $result['settled'],
            number_format($result['value'], 2),
        ));
    }

    public function requestWithdrawal(Request $request, ProvidentFundService $pf): RedirectResponse
    {
        $data = $request->validate([
            'pf_account_id' => ['required', 'exists:pf_accounts,id'],
            'type' => ['required', 'in:partial,final_settlement,loan_adjustment'],
            'requested_amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $account = PfAccount::query()->findOrFail((int) $data['pf_account_id']);
        $pf->requestWithdrawal($account, $data, (int) $request->user()->id);

        return back()->with('success', 'Withdrawal request recorded and waiting for approval.');
    }

    public function decideWithdrawal(Request $request, ProvidentFundService $pf): RedirectResponse
    {
        $data = $request->validate([
            'withdrawal_id' => ['required', 'exists:pf_withdrawals,id'],
            'decision' => ['required', 'in:approve,reject,pay'],
            'approved_amount' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $withdrawal = PfWithdrawal::query()->findOrFail((int) $data['withdrawal_id']);
        $pf->decideWithdrawal(
            $withdrawal,
            $data['decision'],
            isset($data['approved_amount']) ? (float) $data['approved_amount'] : null,
            (int) $request->user()->id,
            $data['note'] ?? null,
        );

        return back()->with('success', match ($data['decision']) {
            'reject' => 'Withdrawal rejected.',
            'approve' => 'Withdrawal approved; pay it to move the money in the ledger.',
            default => 'Withdrawal paid and posted to the ledger.',
        });
    }

    public function withdrawals(Request $request, ProvidentFundService $pf): Response
    {
        $dt = DataTableQuery::fromRequest($request, ['id'], ['status', 'type']);

        return Inertia::render('PAYROLL::ProvidentFund/Withdrawals', [
            'withdrawals' => PaginatedResource::from($pf->withdrawals($dt)),
            'canManage' => (bool) $request->user()?->can('payroll.pf.manage'),
            'filterDefs' => [
                [
                    'key' => 'status',
                    'label' => 'Status',
                    'kind' => 'select',
                    'options' => [
                        ['value' => 'pending', 'label' => 'Pending'],
                        ['value' => 'approved', 'label' => 'Approved'],
                        ['value' => 'paid', 'label' => 'Paid'],
                        ['value' => 'rejected', 'label' => 'Rejected'],
                    ],
                ],
                [
                    'key' => 'type',
                    'label' => 'Type',
                    'kind' => 'select',
                    'options' => [
                        ['value' => 'partial', 'label' => 'Partial withdrawal'],
                        ['value' => 'final_settlement', 'label' => 'Final settlement'],
                        ['value' => 'loan_adjustment', 'label' => 'Loan adjustment'],
                    ],
                ],
            ],
        ]);
    }
}
