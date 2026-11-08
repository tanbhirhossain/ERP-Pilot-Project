<?php

namespace Modules\SALES\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\SALES\Models\PosCollection;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

/**
 * The POS day-end and collection report — report item 39.
 *
 * The day-end posting answers "what did this till do today" by writing one journal per drawer. This
 * service answers the question behind it — *which cashier took which money, who collected what, and
 * does the counted drawer agree with the books* — by reading the same three tables the posting reads:
 * `pos_sales` for what was rung up, `pos_collections` for money taken against an earlier credit sale,
 * and `pos_sessions` for the drawer that owns both.
 *
 * Three rules hold the figures to the ledger, and each one is a way a report like this usually goes
 * wrong:
 *
 *  1. **A collection belongs to the drawer that took the money**, not to the sale it settles. A credit
 *     sale rung up by one cashier on Tuesday and collected by another on Wednesday is Tuesday's credit
 *     and Wednesday's collection — which is how `PosDayEndPostingService` posts it, so attributing it
 *     any other way would make this report disagree with the journal it explains.
 *
 *  2. **`cash` and `bank` are sale tender only.** A closed session's stored `cash_total` *includes*
 *     counter collections taken in that drawer, because the collection's cash physically went into it.
 *     So the row publishes both: `cash` (what sales brought in) and `drawer_cash` (what the drawer is
 *     holding, = sale cash + collected cash). `expected_cash` reconciles against `drawer_cash`, never
 *     against `cash`, and a report that mixed the two would show a variance equal to the collections.
 *
 *  3. **An open drawer is derived from its children.** Its stored totals are only written at the close,
 *     so reading them reports zeros for every till still trading — exactly when somebody wants to look.
 *     For a *closed* drawer the stored totals are authoritative instead: they are the numbers the
 *     journal was posted from, and this report must not contradict the ledger.
 *
 * All money leaves as decimal text (`^-?\d+\.\d{2}$`), never as a float, and aggregation happens in SQL
 * in integer cents — `ROUND(SUM(x) * 100)` — because summing decimals as floats is how a report drops
 * a cent on a large enough day and still looks right.
 */
class PosReportService
{
    /**
     * One row per drawer in range, a per-cashier breakdown, and totals.
     *
     * @param  array{from?: string|null, to?: string|null, user_id?: int|null, warehouse_id?: int|null, status?: string|null}  $filters
     * @return array{rows: array<int, array<string, mixed>>, byUser: array<int, array<string, mixed>>, totals: array<string, mixed>, filters: array<string, mixed>, range: array{from: string|null, to: string|null}}
     */
    public function dayEnd(array $filters = []): array
    {
        $from = $this->dateOrNull($filters['from'] ?? null);
        $to = $this->dateOrNull($filters['to'] ?? null);
        // Read once, then closed over. `when()` hands the callback the *value it was given*, so
        // `when($x !== null, fn ($q, $v) => …)` passes `true` — which silently filters to nothing
        // rather than raising an error, and a report that returns no rows looks like a day with no
        // drawers rather than like a broken filter.
        $userId = $filters['user_id'] ?? null;
        $warehouseId = $filters['warehouse_id'] ?? null;
        $status = $filters['status'] ?? null;

        $sessions = PosSession::query()
            ->with(['warehouse:id,name,code', 'opener:id,name', 'journal:id,journal_no'])
            // The drawer's day is the day it opened: a session opened at 23:50 and closed at 02:10
            // belongs to the day it started, which is also how the close screen and the Z-report read.
            ->when($from !== null, fn ($q) => $q->whereDate('opened_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('opened_at', '<=', $to))
            ->when($userId !== null, fn ($q) => $q->where('opened_by', $userId))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get();

        if ($sessions->isEmpty()) {
            return [
                'rows' => [],
                'byUser' => [],
                'totals' => $this->blankTotals(),
                'filters' => $filters,
                'range' => ['from' => $from, 'to' => $to],
            ];
        }

        $ids = $sessions->pluck('id')->all();
        $saleAgg = $this->saleAggregates($ids);
        $collectionAgg = $this->collectionAggregates($ids);

        $rows = $sessions
            ->map(fn (PosSession $session): array => $this->row($session, $saleAgg, $collectionAgg))
            ->all();

        return [
            'rows' => $rows,
            'byUser' => $this->byUser($rows),
            'totals' => $this->totals($rows),
            'filters' => $filters,
            'range' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * The collection register: every payment taken at a counter against a credit sale.
     *
     * The day-end report folds collections into the drawer that took them, which is right for
     * reconciling a till and is what the ledger posts. It is the wrong shape for the question a
     * manager actually asks about credit — who collected what, from whom, against which sale, and
     * by which method — and until this method existed there was nowhere to ask it: `pos_collections`
     * rows could only be seen one at a time on a sale's own page, or as a single summed figure inside
     * a drawer row.
     *
     * Two things this reports that nothing else did:
     *
     *  - collections are grouped by `collected_by`, the person who actually took the money. The
     *    day-end's per-cashier figures come from the drawer's `opened_by` instead, so when two
     *    cashiers work one supervisor's drawer the day-end cannot tell them apart. Both views are
     *    correct for their own question and this one is the collector's.
     *  - there is deliberately no "money with no drawer" bucket here. That cannot happen, and it is
     *    enforced twice over: `pos_collections.session_id` is NOT NULL, and `PosService::collectDue`
     *    refuses a payment with no open session to take it in. The day-end's blind spot was never
     *    whether a collection exists — it was *who* took it, which is the gap this closes.
     *
     * @param  array{from?: string|null, to?: string|null, user_id?: int|null, partner_id?: int|null, method?: string|null}  $filters
     * @return array{rows: array<int, array<string, mixed>>, byCollector: array<int, array<string, mixed>>, totals: array<string, mixed>, range: array{from: string|null, to: string|null}}
     */
    public function collections(array $filters = []): array
    {
        $from = $this->dateOrNull($filters['from'] ?? null);
        $to = $this->dateOrNull($filters['to'] ?? null);
        // Read once, then closed over: `when()` hands the callback the value it was given, so
        // `when($x !== null, fn ($q, $v) => …)` would pass `true` and filter to nothing.
        $collectorId = $filters['user_id'] ?? null;
        $partnerId = $filters['partner_id'] ?? null;
        $method = $filters['method'] ?? null;

        $collections = PosCollection::query()
            ->with(['collector:id,name', 'partner:id,name', 'sale:id,pos_no', 'session:id,session_no', 'bank:id,name'])
            ->when($from !== null, fn ($q) => $q->whereDate('collected_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('collected_at', '<=', $to))
            ->when($collectorId !== null, fn ($q) => $q->where('collected_by', $collectorId))
            ->when($partnerId !== null, fn ($q) => $q->where('partner_id', $partnerId))
            ->when($method !== null, fn ($q) => $q->where('method', $method))
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        $grouped = [];
        $totalC = 0;
        $cashC = 0;
        $bankC = 0;

        foreach ($collections as $collection) {
            $amountC = $this->cents($collection->amount);
            $isCash = $collection->method === 'cash';
            $totalC += $amountC;
            $isCash ? $cashC += $amountC : $bankC += $amountC;

            $rows[] = [
                'id' => $collection->id,
                'collection_no' => $collection->collection_no,
                'collected_at' => $collection->collected_at?->toIso8601String(),
                'collector' => $collection->collector?->name,
                'collector_id' => $collection->collected_by,
                'customer' => $collection->partner?->name ?? $collection->sale?->customer_name,
                'partner_id' => $collection->partner_id,
                'sale_id' => $collection->pos_sale_id,
                'sale_no' => $collection->sale?->pos_no,
                'method' => $collection->method,
                'bank' => $collection->bank?->name,
                'session_no' => $collection->session?->session_no,
                'session_id' => $collection->session_id,
                'amount' => $this->money($amountC),
                'notes' => $collection->notes,
            ];

            $key = (int) $collection->collected_by;
            $bucket = $grouped[$key] ?? [
                'user_id' => $collection->collected_by,
                'collector' => $collection->collector?->name,
                'collections' => 0,
                '_cents' => ['total' => 0, 'cash' => 0, 'bank' => 0],
            ];
            $bucket['collections']++;
            $bucket['collector'] = $bucket['collector'] ?? $collection->collector?->name;
            $bucket['_cents']['total'] += $amountC;
            $bucket['_cents'][$isCash ? 'cash' : 'bank'] += $amountC;
            $grouped[$key] = $bucket;
        }

        $byCollector = [];
        foreach ($grouped as $bucket) {
            $cents = $bucket['_cents'];
            unset($bucket['_cents']);
            foreach ($cents as $field => $value) {
                $bucket[$field] = $this->money($value);
            }
            $byCollector[] = $bucket;
        }
        usort($byCollector, fn ($a, $b): int => $this->cents($b['total']) <=> $this->cents($a['total']));

        return [
            'rows' => $rows,
            'byCollector' => $byCollector,
            'totals' => [
                'collections' => count($rows),
                'total' => $this->money($totalC),
                'cash' => $this->money($cashC),
                'bank' => $this->money($bankC),
            ],
            'range' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * One drawer's Z-report: the session, what it rang up, and what it collected.
     *
     * @return array{session: PosSession, sales: array<int, array<string, mixed>>, collections: array<int, array<string, mixed>>}
     */
    public function sessionDetail(int $sessionId): array
    {
        $session = PosSession::findOrFail($sessionId);

        $sales = PosSale::query()
            ->withCount('items')
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->get()
            ->map(fn (PosSale $sale): array => [
                'id' => $sale->id,
                'pos_no' => $sale->pos_no,
                'customer' => $sale->customer_name,
                'payment_method' => $sale->payment_method,
                'status' => $sale->status,
                'items' => (int) $sale->items_count,
                'time' => $sale->created_at?->toIso8601String(),
                'total' => $this->money($this->cents($sale->total_amount)),
                'due' => $this->money($this->cents($sale->due_amount)),
                'collected' => $this->money($this->cents($sale->collected_amount)),
            ])
            ->all();

        $collections = PosCollection::query()
            ->with(['sale:id,pos_no,customer_name', 'partner:id,name'])
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->get()
            ->map(fn (PosCollection $collection): array => [
                'id' => $collection->id,
                'collection_no' => $collection->collection_no,
                'time' => $collection->collected_at?->toIso8601String(),
                // Named from the sale when the collection settles one of ours, from the partner when it
                // settles an invoice raised outside the till, and left null when neither is known.
                'customer' => $collection->sale?->customer_name ?? $collection->partner?->name,
                'sale_no' => $collection->sale?->pos_no,
                'method' => $collection->method,
                'amount' => $this->money($this->cents($collection->amount)),
            ])
            ->all();

        return ['session' => $session, 'sales' => $sales, 'collections' => $collections];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aggregation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Per-session sale figures, in integer cents, voids excluded from the money and counted separately.
     *
     * A void is not a transaction and its money leaves the day — but it is still counted, because
     * "how many sales did you cancel" is one of the two numbers a supervisor audits a till by.
     *
     * @param  array<int, int>  $ids
     */
    private function saleAggregates(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $live = "CASE WHEN status <> 'void' THEN %s ELSE 0 END";

        return DB::table('pos_sales')
            ->selectRaw('session_id')
            ->selectRaw("SUM(CASE WHEN status <> 'void' THEN 1 ELSE 0 END) AS transactions")
            ->selectRaw("SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS voids")
            ->selectRaw('ROUND(SUM('.sprintf($live, 'subtotal').') * 100) AS gross_c')
            ->selectRaw('ROUND(SUM('.sprintf($live, 'discount_amount').') * 100) AS discount_c')
            ->selectRaw('ROUND(SUM('.sprintf($live, 'vat_amount').') * 100) AS vat_c')
            ->selectRaw('ROUND(SUM('.sprintf($live, 'cost_total').') * 100) AS cogs_c')
            ->selectRaw('ROUND(SUM('.sprintf($live, 'cash_amount').') * 100) AS cash_c')
            ->selectRaw('ROUND(SUM('.sprintf($live, 'bank_amount').') * 100) AS bank_c')
            ->selectRaw('ROUND(SUM('.sprintf($live, 'due_amount').') * 100) AS due_c')
            ->whereIn('session_id', $ids)
            // `pos_sales` soft-deletes, and a raw query would happily total the deleted rows.
            ->whereNull('deleted_at')
            ->groupBy('session_id')
            ->get()
            ->keyBy('session_id');
    }

    /**
     * Per-session collection figures, attributed to the session whose drawer took the money.
     *
     * `method = 'cash'` splits exactly as `PosCollection::isCash()` does, so this report and the
     * day-end journal cannot disagree about which leg a collection went down.
     *
     * @param  array<int, int>  $ids
     */
    private function collectionAggregates(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table('pos_collections')
            ->selectRaw('session_id')
            ->selectRaw('COUNT(*) AS collections')
            ->selectRaw('ROUND(SUM(amount) * 100) AS amount_c')
            ->selectRaw("ROUND(SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) * 100) AS cash_c")
            ->selectRaw("ROUND(SUM(CASE WHEN method <> 'cash' THEN amount ELSE 0 END) * 100) AS bank_c")
            ->whereIn('session_id', $ids)
            ->groupBy('session_id')
            ->get()
            ->keyBy('session_id');
    }

    /** One report row for one drawer. */
    private function row(PosSession $session, Collection $saleAgg, Collection $collectionAgg): array
    {
        $sale = $saleAgg[$session->id] ?? null;
        $collected = $collectionAgg[$session->id] ?? null;

        $transactions = (int) ($sale->transactions ?? 0);
        $voids = (int) ($sale->voids ?? 0);
        $collectionCount = (int) ($collected->collections ?? 0);

        // Sale tender, which is what the till rang up. Never includes counter collections.
        $cashC = (int) ($sale->cash_c ?? 0);
        $bankC = (int) ($sale->bank_c ?? 0);

        $collectedC = (int) ($collected->amount_c ?? 0);
        $collectedCashC = (int) ($collected->cash_c ?? 0);
        $collectedBankC = (int) ($collected->bank_c ?? 0);

        $openingC = $this->cents($session->opening_cash);

        if ($session->status === 'closed') {
            // Authoritative: these are the figures the day-end journal was posted from.
            $netC = $this->cents($session->net_sales_total);
            $vatC = $this->cents($session->vat_total);
            $discountC = $this->cents($session->discount_total);
            $cogsC = $this->cents($session->cogs_total);
            $dueC = $this->cents($session->due_total);
            $collectedC = $this->cents($session->collected_total);
            // What the drawer holds: sale cash plus the cash collections taken here. This — not
            // `cash` — is what the counted drawer is reconciled against.
            $drawerCashC = $this->cents($session->cash_total);
            $drawerBankC = $this->cents($session->bank_total);
            $expectedC = $session->expected_cash !== null
                ? $this->cents($session->expected_cash)
                : $openingC + $drawerCashC;
        } else {
            // Derived, because the stored totals are only written at the close.
            $grossC = (int) ($sale->gross_c ?? 0);
            $discountC = (int) ($sale->discount_c ?? 0);
            $netC = $grossC - $discountC;
            $vatC = (int) ($sale->vat_c ?? 0);
            $cogsC = (int) ($sale->cogs_c ?? 0);
            $dueC = (int) ($sale->due_c ?? 0);
            $drawerCashC = $cashC + $collectedCashC;
            $drawerBankC = $bankC + $collectedBankC;
            $expectedC = $openingC + $drawerCashC;
        }

        $closingC = $session->closing_cash !== null ? $this->cents($session->closing_cash) : null;
        $varianceC = $closingC !== null
            // A shortage is negative, not absolute: the sign is the information.
            ? ($session->cash_difference !== null
                ? $this->cents($session->cash_difference)
                : $closingC - $expectedC)
            : null;

        return [
            'session_id' => $session->id,
            'session_no' => $session->session_no,
            'cashier' => $session->opener?->name,
            'user_id' => $session->opened_by,
            'warehouse' => $session->warehouse?->name,
            'warehouse_id' => $session->warehouse_id,
            'date' => $session->opened_at?->toDateString(),
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'status' => $session->status,

            'transactions' => $transactions,
            'voids' => $voids,
            'collections' => $collectionCount,

            'gross' => $this->money($netC + $discountC),
            'discount' => $this->money($discountC),
            'net' => $this->money($netC),
            'vat' => $this->money($vatC),
            'cogs' => $this->money($cogsC),

            'cash' => $this->money($cashC),
            'bank' => $this->money($bankC),
            'due' => $this->money($dueC),
            'collected' => $this->money($collectedC),
            // What the drawer physically took in: sale tender plus counter collections.
            'takings' => $this->money($cashC + $bankC + $collectedC),
            'drawer_cash' => $this->money($drawerCashC),
            'drawer_bank' => $this->money($drawerBankC),

            'opening_cash' => $this->money($openingC),
            'expected_cash' => $this->money($expectedC),
            'closing_cash' => $closingC !== null ? $this->money($closingC) : null,
            'variance' => $varianceC !== null ? $this->money($varianceC) : null,

            'journal_id' => $session->journal_id !== null ? (int) $session->journal_id : null,
            'journal_no' => $session->journal?->journal_no,
            'posted_at' => $session->posted_at?->toIso8601String(),
        ];
    }

    /**
     * The same figures rolled up per cashier, busiest drawer first.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function byUser(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $key = (int) $row['user_id'];
            $bucket = $grouped[$key] ?? [
                'user_id' => $row['user_id'],
                'cashier' => $row['cashier'],
                'sessions' => 0,
                'transactions' => 0,
                'voids' => 0,
                'collections' => 0,
                '_cents' => [
                    'net' => 0, 'vat' => 0, 'discount' => 0, 'cogs' => 0, 'cash' => 0, 'bank' => 0,
                    'due' => 0, 'collected' => 0, 'takings' => 0, 'variance' => 0,
                ],
            ];

            $bucket['sessions']++;
            $bucket['transactions'] += (int) $row['transactions'];
            $bucket['voids'] += (int) $row['voids'];
            $bucket['collections'] += (int) $row['collections'];
            // A drawer still open has no counted variance yet, and treating that as zero is right:
            // there is nothing to be short of until somebody counts it.
            $bucket['cashier'] = $bucket['cashier'] ?? $row['cashier'];

            foreach (['net', 'vat', 'discount', 'cogs', 'cash', 'bank', 'due', 'collected', 'takings', 'variance'] as $field) {
                $bucket['_cents'][$field] += $this->cents($row[$field] ?? 0);
            }

            $grouped[$key] = $bucket;
        }

        $out = [];

        foreach ($grouped as $bucket) {
            $cents = $bucket['_cents'];
            unset($bucket['_cents']);

            foreach ($cents as $field => $value) {
                $bucket[$field] = $this->money((int) $value);
            }

            $out[] = $bucket;
        }

        usort($out, fn (array $a, array $b): int => $this->cents($b['takings']) <=> $this->cents($a['takings']));

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $totals = $this->blankTotals();
        $cents = [
            'net' => 0, 'vat' => 0, 'discount' => 0, 'cogs' => 0, 'cash' => 0, 'bank' => 0,
            'due' => 0, 'collected' => 0, 'takings' => 0, 'variance' => 0,
        ];

        foreach ($rows as $row) {
            $totals['sessions']++;
            $totals['transactions'] += (int) $row['transactions'];
            $totals['voids'] += (int) $row['voids'];
            $totals['collections'] += (int) $row['collections'];

            foreach (array_keys($cents) as $field) {
                $cents[$field] += $this->cents($row[$field] ?? 0);
            }
        }

        foreach ($cents as $field => $value) {
            $totals[$field] = $this->money((int) $value);
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    private function blankTotals(): array
    {
        return [
            'sessions' => 0,
            'transactions' => 0,
            'voids' => 0,
            'collections' => 0,
            'net' => '0.00', 'vat' => '0.00', 'discount' => '0.00', 'cogs' => '0.00',
            'cash' => '0.00', 'bank' => '0.00', 'due' => '0.00', 'collected' => '0.00',
            'takings' => '0.00', 'variance' => '0.00',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Money
    // ─────────────────────────────────────────────────────────────────────────

    /** Integer cents from anything the schema or a query can hand back. */
    private function cents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round(((float) $value) * 100);
    }

    /** Decimal text, so a figure can never reach a template as a float. */
    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
