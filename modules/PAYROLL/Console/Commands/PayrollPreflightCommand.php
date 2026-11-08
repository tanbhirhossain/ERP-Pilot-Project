<?php

namespace Modules\PAYROLL\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PayrollPreflightCommand extends Command
{
    protected $signature = 'payroll:preflight {--strict : Exit with a failure code when any issue is found}';

    protected $description = 'Check Payroll data for duplicate bonus runs and unsafe salary-sheet states.';

    public function handle(): int
    {
        $issues = 0;

        if (Schema::hasTable('festival_bonus_runs')) {
            $duplicates = DB::table('festival_bonus_runs')
                ->select('festival_bonus_policy_id', 'pay_year', 'pay_month')
                ->groupBy('festival_bonus_policy_id', 'pay_year', 'pay_month')
                ->havingRaw('COUNT(*) > 1')->get();
            foreach ($duplicates as $duplicate) {
                $ids = DB::table('festival_bonus_runs')->where('festival_bonus_policy_id', $duplicate->festival_bonus_policy_id)
                    ->where('pay_year', $duplicate->pay_year)->where('pay_month', $duplicate->pay_month)
                    ->orderBy('id')->pluck('id')->implode(', ');
                $this->error("Duplicate bonus runs: policy {$duplicate->festival_bonus_policy_id}, {$duplicate->pay_year}-{$duplicate->pay_month}; run IDs [{$ids}]. Reconcile before generating another run.");
                $issues++;
            }
        } else {
            $this->warn('festival_bonus_runs table is not present; bonus duplicate check skipped.');
            $issues++;
        }

        if (Schema::hasTable('payroll_salary_sheets') && Schema::hasTable('payroll_slips')) {
            $hasSnapshots = Schema::hasTable('payroll_calculation_snapshots');
            $sheets = DB::table('payroll_salary_sheets as sheets')
                ->leftJoin('payroll_slips as slips', 'slips.salary_sheet_id', '=', 'sheets.id')
                ->select('sheets.id', 'sheets.pay_year', 'sheets.pay_month', 'sheets.status', 'sheets.employee_count', 'sheets.payroll_cutlist_id')
                ->selectRaw('COUNT(slips.id) as slip_count')
                ->groupBy('sheets.id', 'sheets.pay_year', 'sheets.pay_month', 'sheets.status', 'sheets.employee_count', 'sheets.payroll_cutlist_id')
                ->get();

            foreach ($sheets as $sheet) {
                if (in_array($sheet->status, ['ready', 'released'], true) && ((int) $sheet->slip_count === 0 || (int) $sheet->slip_count !== (int) $sheet->employee_count)) {
                    $this->error("Salary sheet #{$sheet->id} ({$sheet->pay_year}-{$sheet->pay_month}) is {$sheet->status} but has {$sheet->slip_count} slips for {$sheet->employee_count} employees.");
                    $issues++;
                }
                if (in_array($sheet->status, ['queued', 'processing', 'failed'], true) && (int) $sheet->slip_count > 0 && (! $sheet->payroll_cutlist_id || ($hasSnapshots && ! DB::table('payroll_calculation_snapshots')->where('salary_sheet_id', $sheet->id)->exists()))) {
                    $this->error("Salary sheet #{$sheet->id} has slips but is missing its cutlist or calculation snapshot; do not auto-retry it.");
                    $issues++;
                }
            }
            if (! $hasSnapshots) {
                $this->warn('Calculation snapshot table is not present; run pending PAYROLL migrations before processing new sheets.');
                $issues++;
            }
        } else {
            $this->warn('Salary-sheet/slip tables are not present; salary-sheet checks skipped.');
            $issues++;
        }

        if ($issues === 0) {
            $this->info('No Payroll preflight issues found.');
        }

        return $issues > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
