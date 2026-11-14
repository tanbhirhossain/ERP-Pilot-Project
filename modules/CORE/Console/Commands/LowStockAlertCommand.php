<?php

namespace Modules\CORE\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\CORE\Services\NotificationService;

/**
 * Low-stock alert (Phase 2 — F21).
 *
 * Flags active products whose total on-hand quantity has fallen to or below
 * their reorder level, and notifies inventory staff once per day. Idempotent:
 * a daily cache lock means re-running the command (or the scheduler firing
 * twice) never double-notifies.
 */
class LowStockAlertCommand extends Command
{
    protected $signature = 'erp:low-stock-alerts';

    protected $description = 'Notify inventory staff about products at or below their reorder level (once per day)';

    public function handle(NotificationService $notifications): int
    {
        $lock = 'erp:low-stock-alerts:'.now()->toDateString();
        if (! Cache::add($lock, true, now()->endOfDay())) {
            $this->info('Low-stock alert already sent today — skipping.');

            return self::SUCCESS;
        }

        $low = DB::table('inventory_products as p')
            ->leftJoin('inventory_stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', true)
            ->where('p.reorder_level', '>', 0)
            ->groupBy('p.id', 'p.name', 'p.sku', 'p.reorder_level')
            ->havingRaw('COALESCE(SUM(s.qty_on_hand), 0) <= p.reorder_level')
            ->select('p.id', 'p.name', 'p.sku', 'p.reorder_level', DB::raw('COALESCE(SUM(s.qty_on_hand), 0) as on_hand'))
            ->orderBy('p.name')
            ->get();

        if ($low->isEmpty()) {
            $this->info('No products below reorder level.');
            Log::info('erp:low-stock-alerts: no low-stock products.');

            return self::SUCCESS;
        }

        $count = $notifications->notifyPermission(
            'inventory.products.view',
            'Low stock: '.$low->count().' product(s) at or below reorder level',
            $low->take(5)->map(fn ($p) => "{$p->name} ({$p->sku}): {$p->on_hand} on hand, reorder at {$p->reorder_level}")->implode('; '),
            route('inventory.products.index'),
            'warning',
            ['kind' => 'low-stock', 'count' => $low->count()],
        );

        Log::info('erp:low-stock-alerts: notified '.$count.' user(s) about '.$low->count().' low-stock product(s).');
        $this->info("Notified {$count} user(s) about {$low->count()} low-stock product(s).");

        return self::SUCCESS;
    }
}
