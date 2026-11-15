<?php

namespace Modules\PAYROLL\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\PayrollSlip;

/**
 * The reads behind the salary-sheet workspace.
 *
 * The sheet pages used to show a status pill, two totals and a paginated list of 981 names. That is
 * a database browser, not a payroll desk: it cannot answer the four questions somebody closing a
 * month actually asks — *what am I about to pay for, is it right, who has to sign it, and what is
 * left before I can release it?* Every method here exists to answer one of those, and nothing here
 * writes.
 *
 * Two rules the figures obey:
 *
 *  - **The slip is the source of truth, not the sheet columns.** `gross_total` and friends are a
 *    cache that payroll re-states at the end of a run. A workspace that reports the cache cannot
 *    notice when the cache and the slips disagree, which is exactly the state a re-priced month is
 *    in until it is re-stated. So the headline figures are summed from slips, and the cached columns
 *    are compared against them and reported as a variance.
 *  - **Nothing is extrapolated.** If a number cannot be computed from rows that exist, it is
 *    reported as unavailable rather than annualised from a partial month.
 */
final class PayrollSalarySheetWorkspaceService
{
    /**
     * The period as a human writes it.
     *
     * Every screen in the module says "October 2026" rather than "2026-10". A payroll clerk does not
     * think in zero-padded pairs, and a document that goes to a signature reads badly with them.
     */
    public function label(int $year, int $month): string
    {
        return CarbonImmutable::create($year, $month, 1)?->format('F Y') ?? sprintf('%04d-%02d', $year, $month);
    }

    /**
     * Portfolio-level reading of every sheet that exists.
     *
     * `unreleased_liability` is the number a finance controller wants: payroll that has been computed
     * and is not yet released, i.e. money the company has committed to but not yet become liable for
     * in the ledger. It is summed from slips, so it stays right after a re-pricing.
     */
    public function overview(): array
    {
        $sheets = DB::table('payroll_salary_sheets')->orderByDesc('pay_year')->orderByDesc('pay_month')->get();

        $rows = $sheets->map(function (object $sheet): array {
            $agg = $this->slipAggregate((int) $sheet->id);
            $paid = $this->paymentSplit((int) $sheet->id);

            return [
                'id' => (int) $sheet->id,
                'pay_year' => (int) $sheet->pay_year,
                'pay_month' => (int) $sheet->pay_month,
                'period' => $this->label((int) $sheet->pay_year, (int) $sheet->pay_month),
                'status' => (string) $sheet->status,
                'include_punishments' => (bool) $sheet->include_punishments,
                'created_at' => $sheet->created_at,
                'finished_at' => $sheet->finished_at,
                'slips' => $agg['slips'],
                'gross' => $agg['gross'],
                'deductions' => $agg['deductions'],
                'net' => $agg['net'],
                'withheld_tax' => $agg['tax'],
                'paid_slips' => $paid['paid'],
                'paid_amount' => $paid['paid_amount'],
                'held_slips' => $paid['held'],
                'unpaid_slips' => $paid['unpaid'],
                'failure_message' => $sheet->failure_message,
                // The two facts that decide whether a month is finished:
                'accrual_posted' => $sheet->accrual_journal_id !== null,
                'has_cutlist' => $sheet->payroll_cutlist_id !== null,
            ];
        });

        $computed = $rows->where('slips', '>', 0);

        return [
            'sheets' => $rows->values()->all(),
            'current' => $rows->first(),
            'previous' => $rows->skip(1)->first(),
            'counts' => [
                'sheets' => $rows->count(),
                'ready' => $rows->where('status', 'ready')->count(),
                'released' => $rows->where('status', 'released')->count(),
                'failed' => $rows->where('status', 'failed')->count(),
            ],
            'totals' => [
                'headcount' => (int) $computed->max('slips'),
                'gross' => round((float) $computed->sum('gross'), 2),
                'deductions' => round((float) $computed->sum('deductions'), 2),
                'net' => round((float) $computed->sum('net'), 2),
                'withheld_tax' => round((float) $computed->sum('withheld_tax'), 2),
                'unreleased_net' => round((float) $rows->where('status', 'ready')->sum('net'), 2),
                'waiting_slips' => (int) $rows->where('status', 'ready')->sum('slips'),
                'unpaid_net' => round((float) $computed->sum(fn (array $row) => $row['net'] - $row['paid_amount']), 2),
            ],
        ];
    }

    /** Summed from slips, never from the cached columns. */
    private function slipAggregate(int $sheetId): array
    {
        $row = DB::table('payroll_slips')->where('salary_sheet_id', $sheetId)
            ->selectRaw('COUNT(*) as slips, COALESCE(SUM(gross_salary),0) as gross, COALESCE(SUM(total_deductions),0) as deductions, COALESCE(SUM(net_salary),0) as net')
            ->first();

        // Tax has no column of its own — it lives inside the snapshot — so it is aggregated in PHP
        // rather than with JSON functions that differ between the SQLite the database is on and the
        // MySQL a production deployment would use.
        $tax = 0.0;
        DB::table('payroll_slips')->where('salary_sheet_id', $sheetId)->select('breakdown_json')->orderBy('id')
            ->chunk(500, function (Collection $slips) use (&$tax): void {
                foreach ($slips as $slip) {
                    $decoded = json_decode((string) $slip->breakdown_json, true);
                    $tax += (float) ($decoded['tax']['monthly_tax'] ?? 0);
                }
            });

        return [
            'slips' => (int) ($row->slips ?? 0),
            'gross' => round((float) ($row->gross ?? 0), 2),
            'deductions' => round((float) ($row->deductions ?? 0), 2),
            'net' => round((float) ($row->net ?? 0), 2),
            'tax' => round($tax, 2),
        ];
    }

    private function paymentSplit(int $sheetId): array
    {
        $rows = DB::table('payroll_slips')->where('salary_sheet_id', $sheetId)
            ->selectRaw('payment_status, COUNT(*) as slips, COALESCE(SUM(net_salary),0) as amount')
            ->groupBy('payment_status')->get();

        $paid = $rows->firstWhere('payment_status', 'paid');
        $held = $rows->firstWhere('payment_status', 'hold') ?? $rows->firstWhere('payment_status', 'held');
        $unpaid = $rows->firstWhere('payment_status', 'unpaid');

        return [
            'paid' => (int) ($paid->slips ?? 0),
            'paid_amount' => round((float) ($paid->amount ?? 0), 2),
            'held' => (int) ($held->slips ?? 0),
            'unpaid' => (int) ($unpaid->slips ?? 0),
        ];
    }

    /**
     * The facts about one sheet that a person needs in order to trust it.
     *
     * `variance` is deliberately exposed rather than silently corrected: the sheet columns are a cache
     * written by the run, and a re-priced month leaves them stale until payroll re-states them. Hiding
     * that would mean two different totals for the same month depending on which screen you opened.
     */
    public function sheetContext(int $sheetId): array
    {
        $sheet = DB::table('payroll_salary_sheets')->find($sheetId);
        if ($sheet === null) {
            return [];
        }

        $agg = $this->slipAggregate($sheetId);
        $paid = $this->paymentSplit($sheetId);

        $cutlist = null;
        if ($sheet->payroll_cutlist_id !== null) {
            $row = DB::table('payroll_cutlists')->find($sheet->payroll_cutlist_id);
            $cutlist = $row ? [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
                'generated_at' => $row->generated_at,
                'approved_at' => $row->approved_at,
                'items' => DB::table('payroll_cutlist_items')->where('payroll_cutlist_id', $row->id)->count(),
            ] : null;
        }

        $journal = null;
        if ($sheet->accrual_journal_id !== null) {
            $journalRow = DB::table('acc_journals')->find($sheet->accrual_journal_id);
            $journal = $journalRow ? [
                'id' => (int) $journalRow->id,
                'journal_no' => $journalRow->journal_no,
                'journal_date' => $journalRow->journal_date,
                'status' => $journalRow->status,
                'total_debit' => round((float) $journalRow->total_debit, 2),
            ] : null;
        }

        $release = DB::table('payroll_workflow_audits')
            ->where('subject_type', 'salary_sheet')->where('subject_id', $sheetId)->where('event', 'released')
            ->orderByDesc('occurred_at')->first();

        $advice = DB::table('payroll_bank_advices')->where('salary_sheet_id', $sheetId)->orderByDesc('id')->first();

        return [
            'sheet' => $sheet,
            'period' => $this->label((int) $sheet->pay_year, (int) $sheet->pay_month),
            'period_start' => sprintf('%04d-%02d-01', (int) $sheet->pay_year, (int) $sheet->pay_month),
            'period_end' => CarbonImmutable::create((int) $sheet->pay_year, (int) $sheet->pay_month, 1)?->endOfMonth()?->toDateString(),
            'aggregate' => $agg,
            'payment' => $paid,
            'variance' => [
                'gross' => round((float) $sheet->gross_total - $agg['gross'], 2),
                'deductions' => round((float) $sheet->deduction_total - $agg['deductions'], 2),
                'net' => round((float) $sheet->net_total - $agg['net'], 2),
            ],
            'cutlist' => $cutlist,
            'journal' => $journal,
            'released_at' => $release->occurred_at ?? null,
            'released_by' => $release->actor_id ?? null,
            'advice' => $advice ? [
                'id' => (int) $advice->id,
                'advice_no' => $advice->advice_no,
                'status' => $advice->status,
                'total_amount' => round((float) $advice->total_amount, 2),
            ] : null,
        ];
    }

    /**
     * Every cost centre in the month, by headcount and money.
     *
     * This is the screen that answers "where did the ৳14,011,199 go". Headcount matters as much as
     * money: a department with 40% of the net is usually 40% of the people, and only showing the
     * money makes a small high-paid unit look like an anomaly.
     *
     * The percentages are computed against the sheet total rather than against the page, so the
     * column adds to 100 whatever the filter is doing.
     */
    public function departments(int $sheetId): array
    {
        $rows = DB::table('payroll_slips')->where('salary_sheet_id', $sheetId)
            ->selectRaw("COALESCE(NULLIF(department, ''), 'Unassigned') as department, COUNT(*) as slips, COALESCE(SUM(gross_salary),0) as gross, COALESCE(SUM(total_deductions),0) as deductions, COALESCE(SUM(net_salary),0) as net")
            ->groupBy('department')->orderByDesc('net')->get();

        $slips = DB::table('payroll_slips')->where('salary_sheet_id', $sheetId)->select('department', 'breakdown_json')->orderBy('id')->get();
        $tax = [];
        foreach ($slips as $slip) {
            $key = $slip->department !== null && $slip->department !== '' ? $slip->department : 'Unassigned';
            $decoded = json_decode((string) $slip->breakdown_json, true);
            $tax[$key] = ($tax[$key] ?? 0) + (float) ($decoded['tax']['monthly_tax'] ?? 0);
        }

        $totalNet = (float) $rows->sum('net');
        $totalGross = (float) $rows->sum('gross');
        $totalSlips = (int) $rows->sum('slips');

        return [
            'rows' => $rows->map(fn (object $row): array => [
                'department' => (string) $row->department,
                'slips' => (int) $row->slips,
                'gross' => round((float) $row->gross, 2),
                'deductions' => round((float) $row->deductions, 2),
                'net' => round((float) $row->net, 2),
                'tax' => round($tax[$row->department] ?? 0, 2),
                'headcount_pct' => $totalSlips > 0 ? round(((int) $row->slips / $totalSlips) * 100, 1) : 0.0,
                'net_pct' => $totalNet > 0 ? round(((float) $row->net / $totalNet) * 100, 1) : 0.0,
                'average_net' => (int) $row->slips > 0 ? round((float) $row->net / (int) $row->slips, 2) : 0.0,
                'gross_share_pct' => $totalGross > 0 ? round(((float) $row->gross / $totalGross) * 100, 1) : 0.0,
            ])->all(),
            'totals' => [
                'departments' => $rows->count(),
                'slips' => $totalSlips,
                'gross' => round($totalGross, 2),
                'deductions' => round((float) $rows->sum('deductions'), 2),
                'net' => round($totalNet, 2),
                'tax' => round(array_sum($tax), 2),
                'average_net' => $totalSlips > 0 ? round($totalNet / $totalSlips, 2) : 0.0,
            ],
        ];
    }

    /**
     * The release checklist.
     *
     * Release is a one-way door in this application: payment actions turn on for every slip, and the
     * accrual can then be posted. The old screen offered the button with the sentence "review the
     * cutlist and slips before release" and no indication of whether that review had happened. Each
     * check here is either *blocking* or *advisory*, and the distinction is stated on the screen — a
     * sheet with 869 zero-tax payslips is worth a warning, but it is not a reason to refuse to pay
     * people who have earned their wages.
     */
    public function readiness(int $sheetId): array
    {
        $ctx = $this->sheetContext($sheetId);
        if ($ctx === []) {
            return [];
        }

        $sheet = $ctx['sheet'];
        $agg = $ctx['aggregate'];
        $checks = [];

        $checks[] = $this->check(
            'computed',
            'Every slip is computed',
            $agg['slips'] >= (int) $sheet->employee_count && $agg['slips'] > 0,
            $agg['slips'] > 0
                ? sprintf('%s slip(s) computed for %s employee(s) in the run', number_format($agg['slips']), number_format((int) $sheet->employee_count))
                : 'No slip exists yet — the run has not produced any payable row',
            blocking: true,
        );

        $checks[] = $this->check(
            'balances',
            'The sheet balances',
            abs(($agg['gross'] - $agg['deductions']) - $agg['net']) < 0.01,
            sprintf('Gross %s − deductions %s = net %s', number_format($agg['gross'], 2), number_format($agg['deductions'], 2), number_format($agg['net'], 2)),
            blocking: true,
        );

        $checks[] = $this->check(
            'no_negative',
            'No slip pays a negative net',
            DB::table('payroll_slips')->where('salary_sheet_id', $sheetId)->where('net_salary', '<', 0)->doesntExist(),
            'A deduction larger than the pay would take money from the employee rather than give it to them',
            blocking: true,
        );

        $zeroTax = $this->zeroTaxCount($sheetId);
        $checks[] = $this->check(
            'tax',
            'Income tax was computed',
            $zeroTax === 0,
            $zeroTax === 0
                ? sprintf('%s in tax withheld across the month', number_format($agg['tax'], 2))
                : sprintf('%s of %s slip(s) carry ৳0 income tax — either their pay is below the exemption floor, or payroll ran with no usable tax policy', number_format($zeroTax), number_format($agg['slips'])),
            blocking: false,
        );

        $checks[] = $this->check(
            'cutlist',
            'Attendance cutlist reviewed',
            $ctx['cutlist'] !== null && in_array($ctx['cutlist']['status'], ['approved', 'generated'], true),
            $ctx['cutlist'] === null
                ? 'No attendance cutlist is linked to this sheet, so punishment and overtime cannot be traced to an approved source'
                : sprintf('Cutlist %s · %s item(s) · %s', $ctx['cutlist']['status'], number_format($ctx['cutlist']['items']), $ctx['cutlist']['approved_at'] ? 'approved' : 'awaiting approval'),
            blocking: false,
        );

        $checks[] = $this->check(
            'ledger',
            'Not already posted to the ledger',
            $ctx['journal'] === null,
            $ctx['journal'] === null
                ? 'No accrual has been posted for this month yet'
                : sprintf('Accrual %s is already posted (%s)', $ctx['journal']['journal_no'], number_format($ctx['journal']['total_debit'], 2)),
            blocking: false,
        );

        $blocking = collect($checks)->where('blocking', true)->where('ok', false)->count();

        return [
            'checks' => $checks,
            'blocking_failures' => $blocking,
            // Release itself is permission-gated and status-gated in the controller; this is only a
            // statement about the data, so the button's label can say what releasing will do.
            'can_release_data' => $blocking === 0 && (string) $sheet->status === 'ready',
            'status' => (string) $sheet->status,
        ];
    }

    /** Slips whose snapshot says nothing was withheld. */
    private function zeroTaxCount(int $sheetId): int
    {
        $count = 0;
        DB::table('payroll_slips')->where('salary_sheet_id', $sheetId)->select('breakdown_json')->orderBy('id')
            ->chunk(500, function (Collection $slips) use (&$count): void {
                foreach ($slips as $slip) {
                    $decoded = json_decode((string) $slip->breakdown_json, true);
                    if ((float) ($decoded['tax']['monthly_tax'] ?? 0) <= 0.0) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function check(string $key, string $label, bool $ok, string $detail, bool $blocking): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'detail' => $detail,
            'blocking' => $blocking,
        ];
    }

    /**
     * Where one department's money went, for the department page's drill-down.
     *
     * Returned per slip because that is the only grain payroll keeps: an average per department is a
     * description, but a list of the people inside it is an answer when somebody asks why a unit costs
     * what it costs.
     */
    public function departmentSlips(int $sheetId, string $department, int $limit = 200): array
    {
        $query = PayrollSlip::query()->where('salary_sheet_id', $sheetId);
        if ($department === 'Unassigned') {
            $query->where(fn ($q) => $q->whereNull('department')->orWhere('department', ''));
        } else {
            $query->where('department', $department);
        }

        return $query->orderByDesc('net_salary')->limit($limit)->get(['id', 'employee_id', 'employee_name', 'designation', 'gross_salary', 'total_deductions', 'net_salary'])
            ->map(fn (PayrollSlip $slip): array => [
                'id' => (int) $slip->id,
                'employee_id' => (string) $slip->employee_id,
                'employee_name' => (string) $slip->employee_name,
                'designation' => (string) ($slip->designation ?? ''),
                'gross' => round((float) $slip->gross_salary, 2),
                'deductions' => round((float) $slip->total_deductions, 2),
                'net' => round((float) $slip->net_salary, 2),
            ])->all();
    }

    /** A month-by-month run-rate, so a spike has a baseline to be compared against. */
    public function trend(): array
    {
        return DB::table('payroll_salary_sheets')
            ->where('status', '!=', 'failed')
            ->orderBy('pay_year')->orderBy('pay_month')
            ->get()
            ->map(function (object $sheet): array {
                $agg = DB::table('payroll_slips')->where('salary_sheet_id', $sheet->id)
                    ->selectRaw('COUNT(*) as slips, COALESCE(SUM(gross_salary),0) as gross, COALESCE(SUM(net_salary),0) as net')
                    ->first();

                return [
                    'id' => (int) $sheet->id,
                    'period' => $this->label((int) $sheet->pay_year, (int) $sheet->pay_month),
                    'short' => CarbonImmutable::create((int) $sheet->pay_year, (int) $sheet->pay_month, 1)?->format('M Y'),
                    'status' => (string) $sheet->status,
                    // `trend()` reads a query row, so this aggregate really is an object — unlike the
                    // two array-returning helpers. Naming the difference is cheaper than remembering it.
                    'slips' => (int) ($agg?->slips ?? 0),
                    'gross' => round((float) ($agg?->gross ?? 0), 2),
                    'net' => round((float) ($agg?->net ?? 0), 2),
                    'average' => (int) ($agg?->slips ?? 0) > 0 ? round((float) $agg->net / (int) $agg->slips, 2) : 0.0,
                ];
            })->all();
    }
}
