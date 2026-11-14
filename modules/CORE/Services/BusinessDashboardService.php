<?php

namespace Modules\CORE\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Business KPIs + chart series for the dashboard (Phase 1 — U4/U5).
 *
 * This is a read-only reporting layer: it aggregates posted business documents
 * across modules with a handful of indexed queries and caches the result for a
 * minute. It writes nothing and imports no module models — the dashboard is a
 * cross-cutting concern, so it reads the ledger of record directly rather than
 * pulling each module's service graph into CORE.
 *
 * Every figure is derived from *posted* documents, so drafts never inflate a KPI.
 */
class BusinessDashboardService
{
    private const POSTED_INVOICE = ['posted', 'paid', 'partially_paid', 'overdue'];

    private const POSTED_BILL = ['posted', 'paid', 'partially_paid', 'overdue'];

    /**
     * @return array<string, float|int>
     */
    public function kpis(): array
    {
        return Cache::remember('core.dashboard.business.kpis', now()->addMinute(), function (): array {
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();

            $revenueMtd = (float) DB::table('sales_invoices')
                ->whereIn('status', self::POSTED_INVOICE)
                ->whereBetween('invoice_date', [$monthStart, $monthEnd])
                ->sum('total_amount');

            $invoicesMtd = DB::table('sales_invoices')
                ->whereIn('status', self::POSTED_INVOICE)
                ->whereBetween('invoice_date', [$monthStart, $monthEnd])
                ->count();

            $receivables = (float) DB::table('acc_receivables')
                ->whereNotIn('status', ['paid', 'cancelled', 'void'])
                ->sum('balance');

            $payables = (float) DB::table('acc_payables')
                ->whereNotIn('status', ['paid', 'cancelled', 'void'])
                ->sum('balance');

            $inventoryValue = (float) DB::table('inventory_stocks')->sum('total_value');

            $pendingApprovals = DB::table('approval_requests')->where('status', 'pending')->count();

            return [
                'revenue_mtd' => round($revenueMtd, 2),
                'invoices_mtd' => $invoicesMtd,
                'receivables' => round($receivables, 2),
                'payables' => round($payables, 2),
                'inventory_value' => round($inventoryValue, 2),
                'pending_approvals' => $pendingApprovals,
            ];
        });
    }

    /**
     * Revenue (posted invoices) vs purchases (posted bills) for the last 6 months.
     *
     * @return array{labels: list<string>, revenue: list<float>, purchases: list<float>}
     */
    public function revenueVsPurchases(): array
    {
        return Cache::remember('core.dashboard.business.rev_pur', now()->addMinute(), function (): array {
            $labels = [];
            $revenue = [];
            $purchases = [];

            for ($i = 5; $i >= 0; $i--) {
                $month = now()->copy()->subMonths($i);
                $start = $month->copy()->startOfMonth()->toDateString();
                $end = $month->copy()->endOfMonth()->toDateString();
                $labels[] = $month->format('M');

                $revenue[] = round((float) DB::table('sales_invoices')
                    ->whereIn('status', self::POSTED_INVOICE)
                    ->whereBetween('invoice_date', [$start, $end])
                    ->sum('total_amount'), 2);

                $purchases[] = round((float) DB::table('purchase_bills')
                    ->whereIn('status', self::POSTED_BILL)
                    ->whereBetween('bill_date', [$start, $end])
                    ->sum('total_amount'), 2);
            }

            return ['labels' => $labels, 'revenue' => $revenue, 'purchases' => $purchases];
        });
    }

    /**
     * Open receivables bucketed by days past due — the classic aging donut.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function receivablesAging(): array
    {
        return Cache::remember('core.dashboard.business.ar_aging', now()->addMinute(), function (): array {
            $today = now()->startOfDay();
            $buckets = ['Current' => 0.0, '1–30 days' => 0.0, '31–60 days' => 0.0, '61–90 days' => 0.0, '90+ days' => 0.0];

            $rows = DB::table('acc_receivables')
                ->whereNotIn('status', ['paid', 'cancelled', 'void'])
                ->where('balance', '>', 0)
                ->get(['due_date', 'balance']);

            foreach ($rows as $row) {
                $balance = (float) $row->balance;
                if ($row->due_date === null) {
                    $buckets['Current'] += $balance;

                    continue;
                }
                $daysOverdue = $today->diffInDays(Carbon::parse($row->due_date), false);
                // diffInDays(false) is negative when due_date is in the future.
                $overdue = -$daysOverdue;
                if ($overdue <= 0) {
                    $buckets['Current'] += $balance;
                } elseif ($overdue <= 30) {
                    $buckets['1–30 days'] += $balance;
                } elseif ($overdue <= 60) {
                    $buckets['31–60 days'] += $balance;
                } elseif ($overdue <= 90) {
                    $buckets['61–90 days'] += $balance;
                } else {
                    $buckets['90+ days'] += $balance;
                }
            }

            return [
                'labels' => array_keys($buckets),
                'values' => array_map(fn ($v) => round($v, 2), array_values($buckets)),
            ];
        });
    }
}
