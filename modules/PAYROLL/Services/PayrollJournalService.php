<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Account;
use Modules\PAYROLL\Models\FestivalBonusRun;
use Modules\PAYROLL\Models\PayrollJournalPost;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSettlement;
use Modules\PAYROLL\Models\PayrollSlip;

final class PayrollJournalService
{
    public function __construct(private readonly AccountsPostingProviderInterface $posting) {}

    public function ensurePayrollAccounts(): array
    {
        $required = [
            ['code' => '5210', 'name' => 'Payroll - Basic & Gross Salary', 'account_type' => 'expense', 'nature' => 'debit', 'parent_code' => '5000'],
            ['code' => '5211', 'name' => 'Payroll - Allowances & Benefits', 'account_type' => 'expense', 'nature' => 'debit', 'parent_code' => '5000'],
            ['code' => '5212', 'name' => 'Payroll - Overtime & Extra Duty', 'account_type' => 'expense', 'nature' => 'debit', 'parent_code' => '5000'],
            ['code' => '5213', 'name' => 'Payroll - Bonus Expense', 'account_type' => 'expense', 'nature' => 'debit', 'parent_code' => '5000'],
            ['code' => '5214', 'name' => 'Payroll - Gratuity Expense', 'account_type' => 'expense', 'nature' => 'debit', 'parent_code' => '5000'],
            ['code' => '5215', 'name' => 'Payroll - Employer PF Contribution', 'account_type' => 'expense', 'nature' => 'debit', 'parent_code' => '5000'],
            ['code' => '2121', 'name' => 'Salary Payable', 'account_type' => 'liability', 'nature' => 'credit', 'parent_code' => '2000'],
            ['code' => '2122', 'name' => 'PF Payable', 'account_type' => 'liability', 'nature' => 'credit', 'parent_code' => '2000'],
            ['code' => '2123', 'name' => 'Gratuity Payable', 'account_type' => 'liability', 'nature' => 'credit', 'parent_code' => '2000'],
            ['code' => '2124', 'name' => 'Bonus Payable', 'account_type' => 'liability', 'nature' => 'credit', 'parent_code' => '2000'],
            ['code' => '2125', 'name' => 'Payroll Deductions Payable', 'account_type' => 'liability', 'nature' => 'credit', 'parent_code' => '2000'],
            ['code' => '2311', 'name' => 'Payroll Tax Payable (TDS)', 'account_type' => 'liability', 'nature' => 'credit', 'parent_code' => '2000'],
        ];
        $map = [];
        foreach ($required as $def) {
            $existing = Account::where('code', $def['code'])->first();
            if ($existing) {
                $map[$def['code']] = $existing->id;

                continue;
            }
            $parent = Account::where('code', $def['parent_code'])->first();
            $acc = Account::create([
                'code' => $def['code'], 'name' => $def['name'],
                'account_type' => $def['account_type'], 'nature' => $def['nature'],
                'parent_id' => $parent?->id, 'is_group' => false, 'is_posting' => true,
                'is_active' => true, 'is_system' => true,
                'description' => 'Auto-created by Payroll Finance Bridge',
                'created_by' => auth()->id() ?? 1,
            ]);
            $map[$def['code']] = $acc->id;
            Log::info('PayrollJournalService: created account', ['code' => $def['code'], 'id' => $acc->id]);
        }

        return $map;
    }

    public function postSalaryAccrual(PayrollSalarySheet $sheet): PayrollJournalPost
    {
        return DB::transaction(function () use ($sheet) {
            $sheet->refresh();
            if ($sheet->status !== 'released') {
                throw new \DomainException("Only released sheets can be posted. Current: {$sheet->status}");
            }
            $existing = PayrollJournalPost::where('salary_sheet_id', $sheet->id)->where('posting_type', 'salary_accrual')->first();
            if ($existing) {
                Log::warning('Payroll accrual already posted', ['sheet_id' => $sheet->id, 'journal_id' => $existing->journal_id]);

                return $existing;
            }
            $accounts = $this->ensurePayrollAccounts();
            $slips = PayrollSlip::where('salary_sheet_id', $sheet->id)->get();
            if ($slips->isEmpty()) {
                throw new \DomainException('No slips to post for sheet #'.$sheet->id);
            }
            $byDept = [];
            $totals = ['gross' => 0, 'basic' => 0, 'benefits' => 0, 'ot' => 0, 'deductions' => 0, 'tax' => 0, 'pf_emp' => 0, 'pf_employer' => 0, 'loan' => 0, 'net' => 0];
            foreach ($slips as $slip) {
                $dept = $slip->department ?? ($slip->breakdown_json['employee']['department'] ?? 'Unassigned');
                $dept = trim((string) $dept) ?: 'Unassigned';
                if (! isset($byDept[$dept])) {
                    $byDept[$dept] = ['gross' => 0, 'benefits' => 0, 'ot' => 0, 'deductions' => 0, 'tax' => 0, 'pf' => 0, 'loan' => 0, 'net' => 0, 'count' => 0];
                }
                $gross = (float) $slip->gross_salary;
                $net = (float) $slip->net_salary;
                $ded = (float) $slip->total_deductions;
                $break = $slip->breakdown_json ?? [];
                $tax = (float) ($break['tax']['monthly_tax'] ?? $break['tax']['amount'] ?? 0);
                $pfEmp = (float) ($break['provident_fund']['employee_contribution'] ?? $break['pf']['employee_contribution'] ?? 0);
                $pfEmplr = (float) ($break['provident_fund']['employer_contribution'] ?? $break['pf']['employer_contribution'] ?? 0);
                $loanAmt = (float) ($break['loan_installments']['amount'] ?? $break['loans']['amount'] ?? 0);
                $benefits = (float) ($slip->total_benefits ?? 0);
                $ot = (float) ($break['cutlist']['overtime_pay'] ?? $break['overtime_pay'] ?? 0) + (float) ($break['cutlist']['extra_duty_pay'] ?? 0);
                $byDept[$dept]['gross'] += $gross;
                $byDept[$dept]['benefits'] += $benefits;
                $byDept[$dept]['ot'] += $ot;
                $byDept[$dept]['deductions'] += $ded;
                $byDept[$dept]['tax'] += $tax;
                $byDept[$dept]['pf'] += $pfEmp;
                $byDept[$dept]['loan'] += $loanAmt;
                $byDept[$dept]['net'] += $net;
                $byDept[$dept]['count']++;
                $totals['gross'] += $gross;
                $totals['benefits'] += $benefits;
                $totals['ot'] += $ot;
                $totals['deductions'] += $ded;
                $totals['tax'] += $tax;
                $totals['pf_emp'] += $pfEmp;
                $totals['pf_employer'] += $pfEmplr;
                $totals['loan'] += $loanAmt;
                $totals['net'] += $net;
                $totals['basic'] += (float) ($slip->basic_salary ?? 0);
            }
            $lines = [];
            $narration = "Payroll accrual {$sheet->pay_year}-".str_pad((string) $sheet->pay_month, 2, '0', STR_PAD_LEFT)." ({$sheet->employee_count} emps, {$sheet->id})";
            $payDate = sprintf('%04d-%02d-%02d', $sheet->pay_year, $sheet->pay_month, cal_days_in_month(CAL_GREGORIAN, $sheet->pay_month, $sheet->pay_year));
            foreach ($byDept as $dept => $vals) {
                if ($vals['gross'] <= 0) {
                    continue;
                }
                $grossExcludingOt = max(0, $vals['gross'] - $vals['ot']);
                if ($grossExcludingOt > 0.01) {
                    $lines[] = new JournalLineData(accountId: $accounts['5210'], debit: round($grossExcludingOt, 2), description: "Gross salary - {$dept} ({$vals['count']} emps)", costCenter: $dept);
                }
                if ($vals['ot'] > 0.01) {
                    $lines[] = new JournalLineData(accountId: $accounts['5212'], debit: round($vals['ot'], 2), description: "Overtime/Extra Duty - {$dept}", costCenter: $dept);
                }
                $deptPfEmployer = 0;
                foreach ($slips->where('department', $dept) as $s) {
                    $b = $s->breakdown_json ?? [];
                    $deptPfEmployer += (float) ($b['provident_fund']['employer_contribution'] ?? $b['pf']['employer_contribution'] ?? 0);
                }
                if ($deptPfEmployer > 0.01) {
                    $lines[] = new JournalLineData(accountId: $accounts['5215'], debit: round($deptPfEmployer, 2), description: "PF Employer contribution - {$dept}", costCenter: $dept);
                }
            }
            if ($totals['net'] > 0.01) {
                $lines[] = new JournalLineData(accountId: $accounts['2121'], credit: round($totals['net'], 2), description: "Salary payable - net for {$sheet->employee_count} emps");
            }
            if ($totals['tax'] > 0.01) {
                $lines[] = new JournalLineData(accountId: $accounts['2311'], credit: round($totals['tax'], 2), description: 'TDS payable - payroll tax');
            }
            $totalPfCredit = $totals['pf_emp'] + $totals['pf_employer'];
            if ($totalPfCredit > 0.01) {
                $lines[] = new JournalLineData(accountId: $accounts['2122'], credit: round($totalPfCredit, 2), description: "PF payable (emp {$totals['pf_emp']} + emplr {$totals['pf_employer']})");
            }
            if ($totals['loan'] > 0.01) {
                $lines[] = new JournalLineData(accountId: $accounts['2125'], credit: round($totals['loan'], 2), description: 'Loan recovery - staff advances');
            }
            $debitSum = array_sum(array_map(fn ($l) => $l->debit, $lines));
            $creditSum = array_sum(array_map(fn ($l) => $l->credit, $lines));
            if (abs($debitSum - $creditSum) > 0.02) {
                Log::warning('Payroll accrual imbalance, auto-balancing', ['debit' => $debitSum, 'credit' => $creditSum, 'sheet_id' => $sheet->id]);
                $diff = round($debitSum - $creditSum, 2);
                if ($diff > 0) {
                    $lines[] = new JournalLineData(accountId: $accounts['2125'], credit: $diff, description: 'Balancing - other deductions');
                } else {
                    $lines[] = new JournalLineData(accountId: $accounts['5210'], debit: abs($diff), description: 'Balancing - rounding');
                }
            }
            if (count($lines) < 2) {
                throw new \DomainException('Insufficient lines to post accrual (gross/net zero?)');
            }
            $refNo = sprintf('PAY-%04d%02d-%05d', $sheet->pay_year, $sheet->pay_month, $sheet->id);
            $dto = new JournalData(journalDate: $payDate, type: 'JV', referenceNo: $refNo, narration: $narration, lines: $lines, sourceType: 'payroll_sheet', sourceId: $sheet->id);
            $journal = $this->posting->postJournal($dto);
            $post = PayrollJournalPost::create([
                'salary_sheet_id' => $sheet->id, 'posting_type' => 'salary_accrual', 'posting_scope' => 'sheet', 'reference_no' => $refNo,
                'journal_id' => $journal->id, 'total_debit' => $dto->totalDebit(), 'total_credit' => $dto->totalCredit(),
                'status' => 'posted', 'cost_center_breakdown' => $byDept,
                'meta' => ['totals' => $totals, 'lines' => array_map(fn ($l) => ['account_id' => $l->accountId, 'debit' => $l->debit, 'credit' => $l->credit, 'costCenter' => $l->costCenter, 'desc' => $l->description], $lines)],
                'created_by' => auth()->id(),
            ]);
            $sheet->update(['accrual_journal_id' => $journal->id, 'accrual_cost_centers' => $byDept]);
            Log::info('Payroll accrual posted', ['sheet_id' => $sheet->id, 'journal_id' => $journal->id, 'ref' => $refNo, 'cost_centers' => array_keys($byDept)]);

            return $post;
        });
    }

    /**
     * The accrual half of releasing a month.
     *
     * Release and the accrual are one business event: the moment a sheet stops being recalculable is
     * the moment the liability becomes real. Leaving the journal to a separate button meant sheet 4
     * was released on 2026-11-15 and still had no accrual a day later — the payroll and its 88,722.98
     * of withholding existed on the slips and nowhere in the ledger.
     *
     * Why this is not inside release()'s transaction: posting a journal needs an open fiscal year, and
     * refusing to release a month because the accountants have not opened next year would block
     * payroll on bookkeeping. So the release commits first, and this runs after it. If posting then
     * fails for a *transient* reason it is retried; if it fails because of configuration (no fiscal
     * year, no chart of accounts) the released sheet keeps `accrual_journal_id = null` and the finance
     * screen reports the missing journal.
     *
     * What it must never do is fail quietly. The earlier version caught the exception and only wrote
     * to the log, which is how a tax posting silently went missing; the reason is now carried out to
     * the caller and flashed to whoever released the sheet.
     */
    public function postAccrualOnRelease(int $sheetId, ?int $actorId = null): ?PayrollSalarySheet
    {
        $sheet = PayrollSalarySheet::query()->find($sheetId);
        if ($sheet === null || $sheet->status !== 'released') {
            return $sheet;
        }

        if (PayrollJournalPost::query()->where('salary_sheet_id', $sheetId)->where('posting_type', 'salary_accrual')->where('status', 'posted')->exists()) {
            return $sheet;
        }

        $attempts = 2;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $post = $this->postSalaryAccrual($sheet);

                Log::info('Payroll accrual posted on release', [
                    'sheet_id' => $sheetId,
                    'journal_id' => $post->journal_id,
                    'total' => $post->total_debit,
                    'actor_id' => $actorId,
                    'attempt' => $attempt,
                ]);

                session()->flash('success', sprintf(
                    'Sheet released and accrual %s posted (journal #%s, %s BDT).',
                    $post->reference_no,
                    $post->journal_id,
                    number_format((float) $post->total_debit, 2),
                ));

                return $sheet->refresh();
            } catch (\Throwable $e) {
                Log::warning('Payroll accrual could not be posted during release', [
                    'sheet_id' => $sheetId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt === $attempts) {
                    // Say it on the screen, in the words of the thing that actually failed.
                    session()->flash('error', sprintf(
                        'Sheet released, but the accrual journal could not be posted: %s Post it from the finance screen once that is resolved — every slip remains payable meanwhile.',
                        $e->getMessage(),
                    ));
                }
            }
        }

        return $sheet->refresh();
    }

    public function postSalaryPayment(PayrollSlip $slip, int $bankAccountId, string $payDate): PayrollJournalPost
    {
        return DB::transaction(function () use ($slip, $bankAccountId, $payDate) {
            // One payment post per slip, so the idempotence key has to name the slip. It used to
            // read `meta->slip_id`, a JSON path the unique index cannot see, while the index itself
            // was (salary_sheet_id, posting_type) — sheet-shaped. The second slip of any sheet hit
            // `uniq_sheet_posting`, the transaction rolled the journal back with it, and the slip
            // stayed paid with nothing in the ledger (finding 35). `posting_scope` is a real column
            // so the index can carry it: 'sheet' for accruals and settlements, 'slip:<id>' here.
            $scope = 'slip:'.$slip->id;
            $existing = PayrollJournalPost::where('salary_sheet_id', $slip->salary_sheet_id)->where('posting_type', 'salary_payment')->where('posting_scope', $scope)->first();
            if ($existing) {
                return $existing;
            }
            $accounts = $this->ensurePayrollAccounts();
            $amount = (float) $slip->net_salary;
            if ($amount <= 0) {
                throw new \DomainException('Nothing to pay for slip #'.$slip->id);
            }
            $lines = [
                new JournalLineData(accountId: $accounts['2121'], debit: $amount, description: "Salary payment - {$slip->employee_name} ({$slip->employee_id})", costCenter: $slip->cost_center ?? $slip->department),
                new JournalLineData(accountId: $bankAccountId, credit: $amount, description: "Bank payment - {$slip->employee_name}"),
            ];
            $dto = new JournalData(journalDate: $payDate, type: 'PV', referenceNo: sprintf('PAY-PV-%05d-%s', $slip->salary_sheet_id, $slip->employee_id), narration: "Salary payment {$slip->pay_year}-".str_pad((string) $slip->pay_month, 2, '0', STR_PAD_LEFT)." to {$slip->employee_name}", lines: $lines, sourceType: 'payroll_slip', sourceId: $slip->id);
            $journal = $this->posting->postJournal($dto);
            // A slip that reaches the ledger carries no posting error, whatever it carried before.
            $slip->update(['payment_journal_id' => $journal->id, 'payment_posting_error' => null]);

            return PayrollJournalPost::create([
                'salary_sheet_id' => $slip->salary_sheet_id, 'posting_type' => 'salary_payment', 'reference_no' => $dto->referenceNo,
                'posting_scope' => $scope, 'payroll_slip_id' => $slip->id,
                'journal_id' => $journal->id, 'total_debit' => $amount, 'total_credit' => $amount, 'status' => 'posted',
                'meta' => ['slip_id' => $slip->id, 'employee_id' => $slip->employee_id, 'amount' => $amount], 'created_by' => auth()->id(),
            ]);
        });
    }

    public function postSettlement(PayrollSettlement $settlement): PayrollJournalPost
    {
        return DB::transaction(function () use ($settlement) {
            $existing = PayrollJournalPost::where('settlement_id', $settlement->id)->where('posting_type', 'settlement')->first();
            if ($existing) {
                return $existing;
            }
            $accounts = $this->ensurePayrollAccounts();
            $lines = [];
            $gross = (float) $settlement->gross_due;
            $gratuity = (float) $settlement->gratuity_amount;
            $leave = (float) $settlement->leave_encashment;
            $other = (float) $settlement->other_benefits;
            $ded = (float) $settlement->deductions;
            $net = (float) $settlement->net_settlement;
            if ($gross > 0) {
                $lines[] = new JournalLineData(accountId: $accounts['5210'], debit: $gross, description: "Settlement gross due - {$settlement->employee_name}");
            }
            /*
             * Gratuity is an expense in its own right (5214) and sits in its own liability (2123).
             * It used to be credited to Salary Payable with the salary, which let a one-off gratuity
             * hide inside the monthly salary balance — and left 2123 unused while the chart of
             * accounts advertised it.
             */
            if ($gratuity > 0) {
                $lines[] = new JournalLineData(accountId: $accounts['5214'], debit: $gratuity, description: "Gratuity - {$settlement->employee_name}");
            }
            // Leave encashment is a benefit, not gross salary: 5211 is where the chart puts benefits.
            if ($leave > 0) {
                $lines[] = new JournalLineData(accountId: $accounts['5211'], debit: $leave, description: "Leave encashment - {$settlement->employee_name}");
            }
            if ($other > 0) {
                $lines[] = new JournalLineData(accountId: $accounts['5211'], debit: $other, description: "Other benefits - {$settlement->employee_name}");
            }
            if ($gratuity > 0) {
                $lines[] = new JournalLineData(accountId: $accounts['2123'], credit: $gratuity, description: "Gratuity payable - {$settlement->employee_name}");
            }
            /*
             * Salary Payable carries what is actually paid for salary and benefits — `net` minus
             * gratuity, because gratuity has its own payable above. Deductions are then a separate
             * credit. The two together equal gross + leave + other, and the whole journal balances:
             *   debit  = gross + gratuity + leave + other
             *   credit = (net - gratuity) + gratuity + deductions = net + deductions
             * and net + deductions == gross + gratuity + leave + other, asserted just below.
             *
             * The first attempt at this credited gross + benefits here *and* the deductions on top,
             * which was 1,000 too much on the credit side. The balance check in this method is what
             * caught it — the same check that would have caught the original code, had it ever run.
             */
            $salaryCredit = round($net - $gratuity, 2);
            if ($salaryCredit > 0) {
                $lines[] = new JournalLineData(accountId: $accounts['2121'], credit: $salaryCredit, description: "Settlement payable - {$settlement->employee_name}");
            }
            if ($ded > 0) {
                $lines[] = new JournalLineData(accountId: $accounts['2125'], credit: $ded, description: 'Settlement deductions');
            }
            if (abs(round($gross + $gratuity + $leave + $other - $ded - $net, 2)) > 0.01) {
                throw new \DomainException(sprintf(
                    'Settlement #%d does not add up: components %.2f vs net %.2f.',
                    $settlement->id, $gross + $gratuity + $leave + $other - $ded, $net,
                ));
            }
            $debit = array_sum(array_map(fn ($l) => $l->debit, $lines));
            $credit = array_sum(array_map(fn ($l) => $l->credit, $lines));
            if (abs($debit - $credit) > 0.01) {
                throw new \DomainException("Settlement journal not balanced: {$debit} vs {$credit}");
            }
            $dto = new JournalData(journalDate: $settlement->settlement_date->toDateString(), type: 'JV', referenceNo: sprintf('SET-%05d', $settlement->id), narration: "Final settlement {$settlement->employee_name} ({$settlement->employee_id})", lines: $lines, sourceType: 'payroll_settlement', sourceId: $settlement->id);
            $journal = $this->posting->postJournal($dto);

            return PayrollJournalPost::create(['settlement_id' => $settlement->id, 'posting_type' => 'settlement', 'posting_scope' => 'sheet', 'reference_no' => $dto->referenceNo, 'journal_id' => $journal->id, 'total_debit' => $dto->totalDebit(), 'total_credit' => $dto->totalCredit(), 'status' => 'posted', 'created_by' => auth()->id()]);
        });
    }

    /**
     * Posts a released festival bonus run: expense to 5213, liability to 2124.
     *
     * Nothing posted bonus to the ledger at all before this — `payroll_journal_posts.bonus_run_id`
     * existed and was never written, so a released bonus run left the payroll module without ever
     * touching the books. The credit is Bonus Payable rather than Salary Payable because a festival
     * bonus is settled separately from the monthly run and is usually paid in its own disbursement.
     *
     * Idempotent on the run, like the accrual and the settlement: approving twice, or a retry after a
     * timeout, cannot double-book the expense.
     */
    public function postBonus(FestivalBonusRun $run): PayrollJournalPost
    {
        return DB::transaction(function () use ($run) {
            $existing = PayrollJournalPost::where('bonus_run_id', $run->id)
                ->where('posting_type', 'bonus')
                ->where('status', 'posted')
                ->first();
            if ($existing) {
                return $existing;
            }

            $amount = round((float) $run->items()->sum('amount'), 2);
            if ($amount <= 0) {
                throw new \DomainException('Bonus run #'.$run->id.' has nothing payable to post.');
            }

            $accounts = $this->ensurePayrollAccounts();
            $period = sprintf('%d-%02d', $run->pay_year, $run->pay_month);
            $lines = [
                new JournalLineData(accountId: $accounts['5213'], debit: $amount, description: "Festival bonus - {$period} ({$run->employee_count} employees)"),
                new JournalLineData(accountId: $accounts['2124'], credit: $amount, description: "Bonus payable - {$period}"),
            ];

            $dto = new JournalData(
                journalDate: now()->toDateString(),
                type: 'JV',
                referenceNo: sprintf('BON-%05d', $run->id),
                narration: "Festival bonus run #{$run->id} for {$period}",
                lines: $lines,
                sourceType: 'festival_bonus_run',
                sourceId: $run->id,
            );
            $journal = $this->posting->postJournal($dto);

            return PayrollJournalPost::create([
                'bonus_run_id' => $run->id,
                'posting_type' => 'bonus',
                'posting_scope' => 'sheet',
                'reference_no' => $dto->referenceNo,
                'journal_id' => $journal->id,
                'total_debit' => $dto->totalDebit(),
                'total_credit' => $dto->totalCredit(),
                'status' => 'posted',
                'meta' => ['run_id' => $run->id, 'employees' => $run->employee_count, 'period' => $period],
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function voidPosting(PayrollJournalPost $post, string $reason): PayrollJournalPost
    {
        return DB::transaction(function () use ($post, $reason) {
            if ($post->status === 'void') {
                throw new \DomainException('Already voided');
            }
            $this->posting->voidJournal($post->journal_id, $reason, true);
            $post->update(['status' => 'void']);
            Log::info('Payroll journal voided', ['post_id' => $post->id, 'journal_id' => $post->journal_id, 'reason' => $reason]);

            return $post->fresh();
        });
    }
}
