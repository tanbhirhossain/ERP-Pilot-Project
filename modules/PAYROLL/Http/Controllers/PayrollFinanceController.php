<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Bank;
use Modules\PAYROLL\Models\PayrollBankAdvice;
use Modules\PAYROLL\Models\PayrollJournalPost;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Services\PayrollBankAdviceService;
use Modules\PAYROLL\Services\PayrollJournalService;

class PayrollFinanceController extends Controller
{
    public function __construct(
        private readonly PayrollJournalService $journals,
        private readonly PayrollBankAdviceService $advices,
    ) {}

    // ── Salary Sheet Finance Panel ──
    public function showSheetFinance(PayrollSalarySheet $salarySheet): Response
    {
        $sheet = $salarySheet->load(['slips']);
        $posts = PayrollJournalPost::where('salary_sheet_id', $sheet->id)->with('journal')->latest()->get();
        $bankAdvices = PayrollBankAdvice::where('salary_sheet_id', $sheet->id)->with('bank')->withCount('items')->latest()->get();
        $accrualPost = $posts->firstWhere('posting_type', 'salary_accrual');
        $banks = Bank::where('is_active', true)->get(['id', 'name', 'bank_name', 'branch_name', 'account_no']);

        // Whether each advice still agrees with the payroll it was drawn from. The screen could not
        // have noticed otherwise: the advice total agrees with the advice's own frozen line items,
        // and both were correct on the morning they were written. Only the slips can say.
        $adviceChecks = $bankAdvices->mapWithKeys(fn ($advice) => [
            $advice->id => app(\Modules\PAYROLL\Services\PayrollBankAdviceService::class)->reconciliation($advice),
        ]);

        return Inertia::render('PAYROLL::Finance/SheetFinance', [
            'sheet' => $sheet,
            'posts' => $posts,
            'accrualPost' => $accrualPost,
            'bankAdvices' => $bankAdvices,
            'adviceChecks' => $adviceChecks,
            'banks' => $banks,
            'accountsReady' => $this->accountsReady(),
        ]);
    }

    public function postAccrual(PayrollSalarySheet $salarySheet): RedirectResponse
    {
        try {
            $post = $this->journals->postSalaryAccrual($salarySheet);

            return back()->with('success', "Accrual JV posted: {$post->reference_no} (Journal #{$post->journal_id}, {$post->total_debit} BDT, ".count($post->cost_center_breakdown ?? []).' cost centers).');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            \Log::error('Payroll accrual posting failed', ['sheet_id' => $salarySheet->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Posting failed: '.$e->getMessage());
        }
    }

    public function voidPosting(PayrollJournalPost $post, Request $r): RedirectResponse
    {
        $r->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->journals->voidPosting($post, $r->input('reason'));

            return back()->with('success', 'Journal voided and reversal posted.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Bank Advice ──
    public function generateAdvice(Request $r, PayrollSalarySheet $salarySheet): RedirectResponse
    {
        $r->validate(['bank_id' => ['nullable', 'exists:acc_banks,id']]);
        try {
            $result = $this->advices->generateForSheet($salarySheet, $r->input('bank_id') ? (int) $r->input('bank_id') : null);

            // Say what was actually produced, in both halves. An officer who is told "1 advice
            // generated" and nothing else will assume the whole payroll is in it, upload it, and leave
            // the cash-paid employees unpaid.
            $advices = collect($result)->filter(fn ($r) => $r instanceof \Modules\PAYROLL\Models\PayrollBankAdvice);
            $cash = collect($result)->first(fn ($r) => is_array($r) && isset($r['cash_sheet']));

            $parts = [];
            if ($advices->isNotEmpty()) {
                $parts[] = sprintf(
                    '%d bank advice(s) covering %d employee(s), %s BDT — ready for download (BEFTN)',
                    $advices->count(),
                    $advices->sum('employee_count'),
                    number_format((float) $advices->sum('total_amount'), 2),
                );
            }
            if ($cash !== null) {
                $parts[] = sprintf(
                    '%d employee(s) have no bank account and are on a cash sheet for %s BDT — pay them in hand and collect signatures',
                    $cash['employee_count'],
                    number_format((float) $cash['total_amount'], 2),
                );
            }

            if ($parts === []) {
                return back()->with('error', 'Nothing was generated: every slip on this sheet is already paid.');
            }

            return back()->with('success', implode('. ', $parts).'.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function indexAdvices(Request $r): Response
    {
        $advices = PayrollBankAdvice::with(['salarySheet', 'bank'])->withCount('items')->latest()->paginate(20)->withQueryString();

        return Inertia::render('PAYROLL::Finance/BankAdvices', [
            'advices' => $advices,
        ]);
    }

    public function showAdvice(PayrollBankAdvice $advice): Response
    {
        $advice->load(['items', 'salarySheet', 'bank', 'journalPost']);

        return Inertia::render('PAYROLL::Finance/BankAdviceShow', [
            'advice' => $advice,
        ]);
    }

    public function downloadAdvice(PayrollBankAdvice $advice)
    {
        return $this->advices->download($advice);
    }

    public function confirmAdvice(PayrollBankAdvice $advice): RedirectResponse
    {
        try {
            $this->advices->confirm($advice);

            return back()->with('success', 'Advice confirmed. Ready for bank upload.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function markAdvicePaid(PayrollBankAdvice $advice): RedirectResponse
    {
        try {
            $this->advices->markPaid($advice);

            return back()->with('success', 'Marked paid. Slips updated.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function accountsReady(): array
    {
        try {
            $codes = ['5210', '5212', '5215', '2121', '2122', '2311'];
            $ready = [];
            foreach ($codes as $c) {
                $ready[$c] = (bool) Account::where('code', $c)->exists();
            }

            return $ready;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
