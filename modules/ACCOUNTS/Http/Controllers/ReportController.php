<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Payable;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\ACCOUNTS\Services\AccountService;
use Modules\ACCOUNTS\Services\LedgerService;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;

class ReportController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function trial(Request $r): Response
    {
        $asOf = $r->input('as_of', now()->toDateString());
        $tb = $this->ledger->trialBalance($asOf);

        return Inertia::render('ACCOUNTS::Reports/Trial', [
            'asOf' => $asOf,
            'rows' => $tb,
            'totals' => ['debit' => $tb->sum('total_debit'), 'credit' => $tb->sum('total_credit')],
        ]);
    }

    public function profitLoss(Request $r): Response
    {
        $from = $r->input('from', now()->startOfYear()->toDateString());
        $to = $r->input('to', now()->toDateString());

        return Inertia::render('ACCOUNTS::Reports/ProfitLoss', ['report' => $this->ledger->profitAndLoss($from, $to)]);
    }

    public function balanceSheet(Request $r): Response
    {
        $asOf = $r->input('as_of', now()->toDateString());

        return Inertia::render('ACCOUNTS::Reports/BalanceSheet', ['report' => $this->ledger->balanceSheet($asOf)]);
    }

    public function ledger(Request $r): Response
    {
        $accountId = (int) $r->input('account_id');
        $from = $r->input('from');
        $to = $r->input('to');
        $account = $accountId ? Account::find($accountId) : null;

        return Inertia::render('ACCOUNTS::Reports/Ledger', [
            'account' => $account ? ['id' => $account->id, 'code' => $account->code, 'name' => $account->name] : null,
            'from' => $from, 'to' => $to,
            'entries' => $accountId ? $this->ledger->ledgerForAccount($accountId, $from, $to) : collect(),
            'accountOptions' => $accountId ? [] : app(AccountService::class)->options(null, 100),
        ]);
    }

    public function payables(Request $r): Response
    {
        $q = Payable::with(['journal'])->when($r->input('status'), fn ($qq, $v) => $qq->where('status', $v))->latest()->paginate(20);

        return Inertia::render('ACCOUNTS::Reports/Payables', ['payables' => $q, 'filters' => $r->only('status')]);
    }

    public function receivables(Request $r): Response
    {
        $q = Receivable::with(['journal'])->when($r->input('status'), fn ($qq, $v) => $qq->where('status', $v))->latest()->paginate(20);

        return Inertia::render('ACCOUNTS::Reports/Receivables', ['receivables' => $q, 'filters' => $r->only('status')]);
    }

    public function cashFlow(Request $r): Response
    {
        $from = $r->input('from', now()->startOfMonth()->toDateString());
        $to = $r->input('to', now()->toDateString());

        return Inertia::render('ACCOUNTS::Reports/CashFlow', ['report' => $this->ledger->cashFlow($from, $to), 'from' => $from, 'to' => $to]);
    }

    public function depreciation(Request $r): Response
    {
        $asOf = $r->input('as_of', now()->toDateString());

        return Inertia::render('ACCOUNTS::Reports/Depreciation', ['report' => $this->ledger->depreciationSchedule($asOf), 'asOf' => $asOf]);
    }

    // ── P1: Aging Reports (from Vendor Bills / Sales Invoices) ──────────
    public function payablesAging(Request $r): Response
    {
        $asOf = $r->input('as_of', now()->toDateString());
        $bills = VendorBill::with(['vendor'])->where('status', 'posted')->whereColumn('paid_amount', '<', 'total_amount')->get();
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
        $byVendor = [];
        foreach ($bills as $b) {
            $due = $b->due_date?->format('Y-m-d') ?? $b->bill_date->format('Y-m-d');
            $days = max(0, (int) (strtotime($asOf) - strtotime($due)) / 86400);
            $bal = (float) $b->total_amount - (float) $b->paid_amount;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus')));
            $buckets[$bucket] += $bal;
            $vid = $b->vendor_id;
            if (! isset($byVendor[$vid])) {
                $byVendor[$vid] = ['vendor_id' => $vid, 'vendor_name' => $b->vendor?->name ?? 'Vendor #'.$vid, 'total' => 0, 'buckets' => ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0], 'bills' => []];
            }
            $byVendor[$vid]['buckets'][$bucket] += $bal;
            $byVendor[$vid]['total'] += $bal;
            $byVendor[$vid]['bills'][] = ['bill_no' => $b->bill_no, 'bill_date' => $b->bill_date->format('Y-m-d'), 'due_date' => $due, 'days_overdue' => $days, 'amount' => $b->total_amount, 'paid' => $b->paid_amount, 'balance' => $bal, 'bucket' => $bucket];
        }

        return Inertia::render('ACCOUNTS::Reports/PayablesAging', ['asOf' => $asOf, 'buckets' => $buckets, 'byVendor' => array_values($byVendor), 'total' => array_sum($buckets)]);
    }

    public function receivablesAging(Request $r): Response
    {
        $asOf = $r->input('as_of', now()->toDateString());
        $invs = Invoice::with(['customer'])->whereIn('status', ['posted', 'partially_paid', 'overdue'])->whereColumn('paid_amount', '<', 'total_amount')->get();
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
        $byCustomer = [];
        foreach ($invs as $inv) {
            $due = $inv->due_date?->format('Y-m-d') ?? $inv->invoice_date->format('Y-m-d');
            $days = max(0, (int) (strtotime($asOf) - strtotime($due)) / 86400);
            $bal = (float) $inv->total_amount - (float) $inv->paid_amount;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus')));
            $buckets[$bucket] += $bal;
            $cid = $inv->customer_id;
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = ['customer_id' => $cid, 'customer_name' => $inv->customer?->name ?? 'Customer #'.$cid, 'total' => 0, 'buckets' => ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0], 'invoices' => []];
            }
            $byCustomer[$cid]['buckets'][$bucket] += $bal;
            $byCustomer[$cid]['total'] += $bal;
            $byCustomer[$cid]['invoices'][] = ['invoice_no' => $inv->invoice_no, 'invoice_date' => $inv->invoice_date->format('Y-m-d'), 'due_date' => $due, 'days_overdue' => $days, 'amount' => $inv->total_amount, 'paid' => $inv->paid_amount, 'balance' => $bal, 'bucket' => $bucket];
        }

        return Inertia::render('ACCOUNTS::Reports/ReceivablesAging', ['asOf' => $asOf, 'buckets' => $buckets, 'byCustomer' => array_values($byCustomer), 'total' => array_sum($buckets)]);
    }
}
