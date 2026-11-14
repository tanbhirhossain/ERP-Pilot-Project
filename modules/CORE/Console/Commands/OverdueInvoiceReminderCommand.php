<?php

namespace Modules\CORE\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\CORE\Services\NotificationService;

/**
 * Overdue-invoice reminder (Phase 2 — F21).
 *
 * Summarises posted invoices past their due date with an outstanding balance and
 * notifies the sales/AR team once per day. Idempotent via a daily cache lock.
 */
class OverdueInvoiceReminderCommand extends Command
{
    protected $signature = 'erp:overdue-invoice-reminders';

    protected $description = 'Notify sales/AR about overdue invoices with an outstanding balance (once per day)';

    public function handle(NotificationService $notifications): int
    {
        $lock = 'erp:overdue-invoice-reminders:'.now()->toDateString();
        if (! Cache::add($lock, true, now()->endOfDay())) {
            $this->info('Overdue reminder already sent today — skipping.');

            return self::SUCCESS;
        }

        $overdue = DB::table('sales_invoices')
            ->whereNull('deleted_at')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereNotIn('status', ['draft', 'paid', 'void', 'cancelled'])
            ->whereRaw('(total_amount - paid_amount) > 0.005')
            ->select('id', 'invoice_no', 'due_date', DB::raw('(total_amount - paid_amount) as outstanding'))
            ->orderBy('due_date')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue invoices.');
            Log::info('erp:overdue-invoice-reminders: none overdue.');

            return self::SUCCESS;
        }

        $total = (float) $overdue->sum('outstanding');

        $count = $notifications->notifyPermission(
            'sales.invoices.view',
            'Overdue invoices: '.$overdue->count().' totalling '.number_format($total, 2).' BDT',
            $overdue->take(5)->map(fn ($i) => "{$i->invoice_no} (due {$i->due_date}): ".number_format((float) $i->outstanding, 2).' BDT')->implode('; '),
            route('sales.invoices.index'),
            'warning',
            ['kind' => 'overdue-invoices', 'count' => $overdue->count(), 'total' => $total],
        );

        Log::info('erp:overdue-invoice-reminders: notified '.$count.' user(s) about '.$overdue->count().' overdue invoice(s).');
        $this->info("Notified {$count} user(s) about {$overdue->count()} overdue invoice(s).");

        return self::SUCCESS;
    }
}
