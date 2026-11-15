<?php

namespace Modules\PAYROLL\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Actions\CalculateEmployeeTaxAction;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\TaxConfiguration;

/**
 * Re-price income tax on payslips that were calculated without a usable tax policy.
 *
 * This exists because of a specific failure: every payslip on this database was computed with
 * `mode: unconfigured`, so 981 employees had ৳0 withheld while the tax screen displayed a policy
 * that looked complete. The calculator has been fixed, but fixing it changes nothing for a payslip
 * that was already written — the tax is inside `breakdown_json`, which was captured as an immutable
 * snapshot at the time payroll ran.
 *
 * So this command rewrites exactly three numbers on each affected slip: the tax block, the total
 * deductions, and the net salary. Everything else in the snapshot — gross, benefits, PF, loan
 * installments, proration, the cutlist — is left untouched, because none of it depends on tax and
 * recomputing it would be a second, unrequested change.
 *
 * It refuses to touch a slip whose earnings and deductions do not reconcile, a payslip that has
 * already been paid, or a sheet that has been posted to the ledger: in those cases the number is no
 * longer just a number, it is a document somebody signed or a journal somebody posted.
 */
final class RecalculateTax extends Command
{
    protected $signature = 'payroll:recalculate-tax
                            {sheet? : Salary sheet id. Omit for every sheet.}
                            {--apply : Write the changes. Without this the command only reports.}
                            {--date= : Treat this Y-m-d as the tax date instead of the sheet month-end.}';

    protected $description = 'Re-price income tax on already-calculated payslips, leaving every other component untouched.';

    public function handle(CalculateEmployeeTaxAction $calculator): int
    {
        $apply = (bool) $this->option('apply');
        $override = $this->option('date');

        $sheets = PayrollSalarySheet::query()
            ->when($this->argument('sheet'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('pay_year')->orderBy('pay_month')
            ->get();

        if ($sheets->isEmpty()) {
            $this->error('No salary sheet matched.');

            return self::FAILURE;
        }

        $this->line($apply ? '<fg=red>APPLYING</>' : '<fg=yellow>DRY RUN</> — nothing will be written.');
        $totalDelta = 0.0;
        $totalSlips = 0;
        $totalChanged = 0;
        $totalRefused = 0;

        foreach ($sheets as $sheet) {
            $periodEnd = $override
                ? \Carbon\Carbon::parse($override)->toDateString()
                : sprintf('%04d-%02d-%02d', $sheet->pay_year, $sheet->pay_month, cal_days_in_month(CAL_GREGORIAN, $sheet->pay_month, $sheet->pay_year));

            $configuration = TaxConfiguration::query()->with('slabs')->effectiveOn($periodEnd)->orderByDesc('effective_from')->orderByDesc('id')->first();
            $payload = $configuration ? [
                ...$configuration->only(['id', 'name', 'mode', 'fixed_monthly_tax', 'annual_exemption', 'taxable_salary_percent']),
                'slabs' => $configuration->slabs->map(fn ($slab) => $slab->only(['lower_limit', 'upper_limit', 'rate_percent', 'sort_order']))->all(),
            ] : null;

            $this->newLine();
            $this->line(sprintf('<options=bold>Sheet %d — %04d-%02d</> (%s)', $sheet->id, $sheet->pay_year, $sheet->pay_month, $sheet->status));
            $this->line($configuration
                ? sprintf('  policy: #%d %s (%d bands) in force on %s', $configuration->id, $configuration->name, $configuration->slabs->count(), $periodEnd)
                : sprintf('  policy: <fg=red>none in force on %s</> — every slip would recompute to 0.00', $periodEnd));

            if ($sheet->accrual_journal_id !== null) {
                $this->warn('  refused: this sheet is already posted to the ledger; changing slips would leave the accrual describing a payroll that never happened.');
                $totalRefused++;

                continue;
            }

            /*
             * An override only applies to a payroll period it is effective for.
             *
             * `employee_tax_profiles` holds the *current* override with one effective window; the
             * versions table holds the history. Reading the current row and applying it to an old
             * payslip is wrong whenever the override was created after that payslip — which is
             * exactly the case here: the override on L1009001 starts 2026-11-14, so it must not
             * touch an October slip. The version whose window contains the tax date is the answer,
             * and "no version" means "follow the policy", not "follow today's override".
             */
            $profiles = DB::table('payroll_employee_tax_profile_versions')
                ->where('effective_from', '<=', $periodEnd)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $periodEnd))
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->get()
                ->keyBy('employee_id');

            $slips = DB::table('payroll_slips')->where('salary_sheet_id', $sheet->id)->orderBy('id')->get();
            $changed = 0;
            $refused = 0;
            $delta = 0.0;

            foreach ($slips as $slip) {
                $breakdown = json_decode((string) $slip->breakdown_json, true);
                if (! is_array($breakdown)) {
                    $refused++;
                    continue;
                }

                if (($slip->payment_status ?? 'unpaid') !== 'unpaid' || $slip->payment_journal_id !== null) {
                    $refused++;
                    continue;
                }

                $pf = (float) ($breakdown['provident_fund']['employee_contribution'] ?? 0);
                $loan = (float) ($breakdown['loan_installments']['amount'] ?? 0);
                $recurring = 0.0;
                foreach (($breakdown['recurring_deductions'] ?? []) as $row) {
                    $recurring += (float) ($row['amount'] ?? 0);
                }

                // The cutlist deducts three more things that are not "recurring deductions": unpaid
                // leave, missing punches, and the punishment for late/early-out days. They have to be
                // part of the reconciliation or every slip carrying a punishment looks like an
                // unexplained discrepancy — which is exactly how this check first reported three
                // "refused" slips that were in fact perfectly correct.
                $cutlist = $breakdown['cutlist'] ?? [];
                $cutlistDeductions = round(
                    (float) ($cutlist['unpaid_leave'] ?? 0)
                    + (float) ($cutlist['missing_punch'] ?? 0)
                    + (float) ($cutlist['punishment'] ?? 0),
                    2,
                );

                $storedTax = (float) ($breakdown['tax']['monthly_tax'] ?? 0);
                $rebuilt = round($pf + $loan + $recurring + $cutlistDeductions + $storedTax, 2);

                // If the stored deductions are still not the sum of their parts, this slip was written
                // by something this command does not model. Refuse rather than guess.
                if (abs($rebuilt - (float) $slip->total_deductions) > 0.02) {
                    $this->line(sprintf(
                        '    <fg=red>refused</> slip %d %s: components %s vs stored %s',
                        $slip->id, mb_substr((string) $slip->employee_name, 0, 24),
                        number_format($rebuilt, 2), number_format((float) $slip->total_deductions, 2),
                    ));
                    $refused++;
                    continue;
                }

                $taxable = (float) ($breakdown['tax']['allocation']['monthly_taxable_income'] ?? 0);
                $profile = $profiles[$slip->employee_id] ?? null;
                $profilePayload = $profile ? [
                    'override_mode' => $profile->override_mode,
                    'fixed_tax_amount' => $profile->fixed_tax_amount,
                    'fixed_tax_frequency' => $profile->fixed_tax_frequency,
                    'effective_from' => $profile->effective_from,
                    'effective_to' => $profile->effective_to,
                ] : null;

                $fresh = $calculator->execute($taxable, $payload, $profilePayload, $periodEnd);
                $newTax = round((float) $fresh['monthly_tax'], 2);

                if (abs($newTax - $storedTax) < 0.005) {
                    continue;
                }

                $newDeductions = round((float) $slip->total_deductions - $storedTax + $newTax, 2);
                $newNet = round((float) $slip->gross_salary - $newDeductions, 2);

                if ($newNet < 0) {
                    $refused++;
                    continue;
                }

                $breakdown['tax'] = [
                    ...$fresh,
                    'allocation' => [
                        ...($breakdown['tax']['allocation'] ?? []),
                        'configuration_id' => $configuration?->id,
                        'configuration_effective_from' => $configuration?->effective_from?->toDateString(),
                        'profile_override' => $profile?->override_mode ?? 'global_config',
                        'recalculated_at' => now()->toIso8601String(),
                        'recalculated_from_mode' => $breakdown['tax']['mode'] ?? null,
                    ],
                ];
                $breakdown['totals'] = [
                    'gross' => round((float) $slip->gross_salary, 2),
                    'deductions' => $newDeductions,
                    'net' => $newNet,
                ];

                if ($this->output->isVerbose() && $changed < 10) {
                    $this->line(sprintf(
                        '    %-24s taxable %12s  tax %9s -> %9s  net %12s -> %12s',
                        mb_substr((string) $slip->employee_name, 0, 24), number_format($taxable, 2),
                        number_format($storedTax, 2), number_format($newTax, 2),
                        number_format((float) $slip->net_salary, 2), number_format($newNet, 2),
                    ));
                }

                if ($apply) {
                    DB::table('payroll_slips')->where('id', $slip->id)->update([
                        'total_deductions' => $newDeductions,
                        'net_salary' => $newNet,
                        'breakdown_json' => json_encode($breakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                }

                $changed++;
                $delta += $newTax - $storedTax;
            }

            $this->line(sprintf(
                '  %d slip(s) checked · %d changed · %d refused · tax delta %s',
                $slips->count(), $changed, $refused, number_format($delta, 2),
            ));

            if ($apply && $changed > 0) {
                $totals = DB::table('payroll_slips')->where('salary_sheet_id', $sheet->id)
                    ->selectRaw('COALESCE(SUM(gross_salary),0) g, COALESCE(SUM(total_deductions),0) d, COALESCE(SUM(net_salary),0) n')->first();
                DB::table('payroll_salary_sheets')->where('id', $sheet->id)->update([
                    'gross_total' => $totals->g, 'deduction_total' => $totals->d, 'net_total' => $totals->n, 'updated_at' => now(),
                ]);
                $this->line('  sheet totals re-stated from its slips.');
            }

            $totalSlips += $slips->count();
            $totalChanged += $changed;
            $totalRefused += $refused;
            $totalDelta += $delta;
        }

        $this->newLine();
        $this->line(sprintf('<options=bold>%d slip(s), %d changed, %d refused, extra tax %s</>', $totalSlips, $totalChanged, $totalRefused, number_format($totalDelta, 2)));
        if (! $apply) {
            $this->warn('Dry run. Re-run with --apply to write these figures.');
        }

        return self::SUCCESS;
    }
}
