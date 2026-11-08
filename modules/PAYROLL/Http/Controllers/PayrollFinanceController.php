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

        return Inertia::render('PAYROLL::Finance/SheetFinance', [
            'sheet' => $sheet,
            'posts' => $posts,
            'accrualPost' => $accrualPost,
            'bankAdvices' => $bankAdvices,
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
            $advices = $this->advices->generateForSheet($salarySheet, $r->input('bank_id') ? (int) $r->input('bank_id') : null);
            $count = count($advices);

            return back()->with('success', "{$count} bank advice(s) generated. Files ready for download (BEFTN Excel/CSV).");
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
