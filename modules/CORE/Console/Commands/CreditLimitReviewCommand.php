<?php

namespace Modules\CORE\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\CORE\Services\NotificationService;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Services\CreditControlService;

/**
 * Credit-limit review (Phase 2 — F21).
 *
 * Flags customers whose current exposure has reached 80% or more of their credit
 * limit, so finance can act before a sale is refused at the counter. Notifies
 * once per day; idempotent via a daily cache lock.
 */
class CreditLimitReviewCommand extends Command
{
    protected $signature = 'erp:credit-limit-review {--threshold=80 : Exposure percentage of the limit that triggers an alert}';

    protected $description = 'Notify finance about customers near or over their credit limit (once per day)';

    public function handle(NotificationService $notifications, CreditControlService $credit): int
    {
        $lock = 'erp:credit-limit-review:'.now()->toDateString();
        if (! Cache::add($lock, true, now()->endOfDay())) {
            $this->info('Credit-limit review already sent today — skipping.');

            return self::SUCCESS;
        }

        $threshold = max(1, (int) $this->option('threshold')) / 100;

        $flagged = [];
        Partner::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('credit_limit', '>', 0)
            ->whereIn('type', ['customer', 'both'])
            ->orderBy('name')
            ->chunkById(100, function ($partners) use ($credit, $threshold, &$flagged): void {
                foreach ($partners as $partner) {
                    $limit = (float) $partner->credit_limit;
                    $exposure = $credit->currentExposure((int) $partner->id);
                    if ($limit > 0 && $exposure >= $limit * $threshold) {
                        $flagged[] = [
                            'name' => $partner->name,
                            'exposure' => $exposure,
                            'limit' => $limit,
                            'pct' => (int) round($exposure / $limit * 100),
                        ];
                    }
                }
            });

        if ($flagged === []) {
            $this->info('No customers near their credit limit.');
            Log::info('erp:credit-limit-review: no customers near limit.');

            return self::SUCCESS;
        }

        usort($flagged, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        $over = count(array_filter($flagged, fn ($f) => $f['pct'] >= 100));

        $count = $notifications->notifyPermission(
            'sales.invoices.view',
            'Credit review: '.count($flagged).' customer(s) near limit'.($over > 0 ? " ({$over} over)" : ''),
            collect($flagged)->take(5)->map(fn ($f) => "{$f['name']}: ".number_format($f['exposure'], 2).' / '.number_format($f['limit'], 2)." BDT ({$f['pct']}%)")->implode('; '),
            route('partner.customers.index'),
            $over > 0 ? 'error' : 'warning',
            ['kind' => 'credit-review', 'count' => count($flagged), 'over' => $over],
        );

        Log::info('erp:credit-limit-review: notified '.$count.' user(s) about '.count($flagged).' customer(s).');
        $this->info('Notified '.$count.' user(s) about '.count($flagged).' customer(s) near their credit limit.');

        return self::SUCCESS;
    }
}
