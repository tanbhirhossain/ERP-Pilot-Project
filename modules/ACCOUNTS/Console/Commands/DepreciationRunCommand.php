<?php

namespace Modules\ACCOUNTS\Console\Commands;

use Illuminate\Console\Command;
use Modules\ACCOUNTS\Services\DepreciationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Month-end depreciation from the scheduler.
 *
 * Defaults to last month — the close always runs after the month it charges — and is
 * idempotent: an already-run period reports success without writing, so an overlapping or
 * repeated tick is harmless. Anything else that stops a run (a gap in the chain, a closed
 * fiscal month, a misconfigured category) exits non-zero with the reason, so the scheduled
 * run fails loudly instead of posting half a close.
 */
class DepreciationRunCommand extends Command
{
    protected $signature = 'erp:depreciation-run {--period= : Month to run as YYYY-MM (defaults to last month)} {--catch-up : Run every outstanding month through the period}';

    protected $description = 'Post the monthly depreciation run (idempotent)';

    public function handle(DepreciationService $depreciation): int
    {
        $period = $this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m');

        try {
            if ($this->option('catch-up')) {
                $runs = $depreciation->catchUp($period, null, 'Scheduled catch-up');
                $total = array_sum(array_map(fn ($r) => (float) $r->total_amount, $runs));
                $this->info(sprintf(
                    'Depreciation caught up through %s: %d month(s), %s posted.',
                    $period, count($runs), number_format($total, 2)
                ));
            } else {
                $run = $depreciation->run($period, null, 'Scheduled run');
                $this->info($run->line_count > 0
                    ? "Depreciation for {$period} posted: {$run->line_count} assets, ".number_format((float) $run->total_amount, 2).'.'
                    : "Depreciation for {$period}: nothing due — the period is closed off.");
            }

            return self::SUCCESS;
        } catch (HttpException|\DomainException $e) {
            // The service refuses with words; the only refusal that is also a success is the
            // one saying the work is already done.
            if (str_contains($e->getMessage(), 'already been run') || str_contains($e->getMessage(), 'already run')) {
                $this->info("Depreciation for {$period} is already run — nothing to do.");

                return self::SUCCESS;
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
