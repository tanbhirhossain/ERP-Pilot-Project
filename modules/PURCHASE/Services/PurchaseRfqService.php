<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\PurchaseRequisition;
use Modules\PURCHASE\Models\PurchaseRfq;
use Modules\PURCHASE\Models\PurchaseRfqAward;
use Modules\PURCHASE\Models\PurchaseRfqLine;
use Modules\PURCHASE\Models\PurchaseRfqQuote;
use Modules\PURCHASE\Models\PurchaseRfqQuoteItem;

/**
 * F18 — the RFQ round: ask, receive, compare, award.
 *
 * The compare table and the award are the only parts of procurement where the ERP can add value the
 * spreadsheet it replaces did not have, so they live here as *server* logic, not as something the grid
 * computes in the browser:
 *
 *   - **Comparison is per line, the award is per line too.** "Cheapest overall" and "the right award"
 *     are different questions — a vendor with one free delivery can win the total and lose every line.
 *     `compare()` therefore returns both, and `award()` accepts a per-line choice, pre-filled with the
 *     recommendation but owned by the human.
 *   - **A quote is a price with an expiry.** Awarding a quote whose `valid_until` has passed is refused,
 *     not warned: the vendor's number is no longer on the table, and an ERP that quietly accepts it
 *     turns a procurement file into a fiction.
 *   - **A line can only be awarded once, anywhere.** Enforced by the unique key on
 *     `purchase_rfq_awards.rfq_line_id` and re-checked here, so two rounds cannot both buy the same 40
 *     bags and no one has to notice in the receiving queue.
 *   - **The award produces a draft PO, per vendor.** `PurchaseOrderService::create()` is called — the real
 *     path, with the real totals and the real `order_no` generator — rather than inserting rows. So a
 *     converted requisition is not a second way to make a PO, it is a third button on the one way.
 */
class PurchaseRfqService
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->query($dt)->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function findOrFail(int $id): PurchaseRfq
    {
        return PurchaseRfq::with([
            'lines.requisitionItem.product',
            'quotes.vendor',
            'quotes.items',
            'awards.quote.vendor',
            'requisition.warehouse',
        ])->findOrFail($id);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): PurchaseRfq
    {
        return DB::transaction(function () use ($data): PurchaseRfq {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['rfq_no'] = $this->nextNo();
            $data['status'] = 'draft';
            $data['created_by'] = auth()->id();
            unset($data['awarded_quote_id'], $data['awarded_at'], $data['awarded_by']);

            $rfq = PurchaseRfq::create($data);
            $this->syncLines($rfq, is_array($lines) ? $lines : []);

            return $rfq->fresh();
        });
    }

    public function update(PurchaseRfq $rfq, array $data): PurchaseRfq
    {
        if (! $rfq->isDraft()) {
            throw new \DomainException('Only a draft round can be edited — once it is out to the market, the question is fixed.');
        }

        return DB::transaction(function () use ($rfq, $data): PurchaseRfq {
            $lines = $data['lines'] ?? null;
            unset($data['lines'], $data['rfq_no'], $data['status'], $data['created_by'], $data['awarded_quote_id'], $data['awarded_at'], $data['awarded_by']);

            if ($lines !== null) {
                $this->syncLines($rfq, $lines);
            }

            $rfq->update($data);

            return $rfq->fresh();
        });
    }

    public function delete(PurchaseRfq $rfq): void
    {
        if (! $rfq->isDraft()) {
            throw new \DomainException('Only a draft round can be deleted.');
        }

        if ($rfq->quotes()->exists()) {
            throw new \DomainException('A round with quotes on it is a record, not a draft — cancel it instead.');
        }

        $rfq->lines()->delete();
        $rfq->delete();
    }

    public function send(PurchaseRfq $rfq): PurchaseRfq
    {
        if (! $rfq->isDraft()) {
            throw new \DomainException('Only a draft round can be sent.');
        }

        if (! $rfq->lines()->exists()) {
            throw new \DomainException('Send needs at least one line to ask about.');
        }

        $rfq->update(['status' => 'sent', 'sent_at' => now()]);

        return $rfq->fresh();
    }

    /** Stop taking quotes; the comparison and the award stay available either way. */
    public function close(PurchaseRfq $rfq): PurchaseRfq
    {
        if ($rfq->status === 'cancelled') {
            throw new \DomainException('A cancelled round cannot be closed.');
        }

        if ($rfq->status === 'closed') {
            return $rfq;
        }

        $rfq->update(['status' => 'closed']);

        return $rfq->fresh();
    }

    public function cancel(PurchaseRfq $rfq): PurchaseRfq
    {
        if ($rfq->awarded_quote_id !== null) {
            throw new \DomainException('This round has been awarded and purchase orders exist — cancel those, not the record of how they were decided.');
        }

        if (in_array($rfq->status, ['closed', 'cancelled'], true)) {
            throw new \DomainException("A round that is {$rfq->status} cannot be cancelled.");
        }

        $rfq->update(['status' => 'cancelled']);

        return $rfq->fresh();
    }

    /**
     * Enter or replace one vendor's answer.
     *
     * `items` is the whole answer, not a patch: re-keying a quote from a phone means the previous
     * numbers are wrong, and a half-updated quote is the worst thing a comparison can be built on.
     *
     * @param  array<string,mixed>  $data
     */
    public function recordQuote(PurchaseRfq $rfq, array $data): PurchaseRfqQuote
    {
        if ($rfq->awarded_quote_id !== null) {
            throw new \DomainException('This round has been awarded — reopen it with a new round, do not edit the winner.');
        }

        if ($rfq->status === 'cancelled') {
            throw new \DomainException('A cancelled round cannot take quotes.');
        }

        $items = $data['items'] ?? [];

        if (! is_array($items) || $items === []) {
            throw new \DomainException('A quote needs at least one priced line.');
        }

        $vendorId = (int) ($data['vendor_id'] ?? 0);
        $lineIds = $rfq->lines()->pluck('id')->all();

        foreach ($items as $it) {
            $lineId = (int) ($it['rfq_line_id'] ?? 0);

            if (! in_array($lineId, $lineIds, true)) {
                throw new \DomainException('Every quoted line must belong to this round.');
            }
        }

        return DB::transaction(function () use ($rfq, $vendorId, $data, $items): PurchaseRfqQuote {
            $quote = PurchaseRfqQuote::firstOrNew([
                'rfq_id' => $rfq->id,
                'vendor_id' => $vendorId,
            ]);

            $quote->fill([
                'quote_ref' => $data['quote_ref'] ?? null,
                'quoted_on' => $data['quoted_on'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'received',
                'entered_by' => auth()->id(),
            ]);
            $quote->save();

            $quote->items()->delete();

            $poisha = 0;

            foreach ($items as $it) {
                $line = PurchaseRfqLine::find((int) $it['rfq_line_id']);
                $qty = isset($it['qty']) && (float) $it['qty'] > 0
                    ? (float) $it['qty']
                    : (float) ($line->qty ?? 0);
                $price = max(0, (float) ($it['unit_price'] ?? 0));
                $total = $this->multiply($qty, $price);

                PurchaseRfqQuoteItem::create([
                    'quote_id' => $quote->id,
                    'rfq_line_id' => (int) $it['rfq_line_id'],
                    'unit_price' => $price,
                    'qty' => $qty,
                    'line_total' => $total,
                    'notes' => $it['notes'] ?? null,
                ]);

                $poisha += $this->cents($total);
            }

            $quote->update(['total_amount' => $this->centsToAmount($poisha)]);

            return $quote->fresh('items');
        });
    }

    public function withdrawQuote(PurchaseRfqQuote $quote): PurchaseRfqQuote
    {
        if ((int) $quote->rfq->awarded_quote_id === (int) $quote->id) {
            throw new \DomainException('This quote won the round — cancel the purchase order it created first.');
        }

        $quote->update(['status' => 'withdrawn']);

        return $quote->fresh();
    }

    /**
     * The comparison table, as data the screen and the print sheet both render.
     *
     * `cells` is keyed by quote id, and a quote that did not price a line is simply absent — a missing
     * cell and a zero cell mean different things on screen and must not be collapsed into one.
     *
     * @return array{lines: list<array<string,mixed>>, quotes: list<array<string,mixed>>, recommendation: array{quote_id:int,reason:string}|null}
     */
    public function compare(PurchaseRfq $rfq): array
    {
        $rfq->loadMissing(['lines', 'quotes.items', 'quotes.vendor']);

        $quotes = $rfq->quotes->filter(fn (PurchaseRfqQuote $q): bool => ! $q->isWithdrawn())->values();
        $coverage = [];

        foreach ($quotes as $q) {
            foreach ($q->items as $item) {
                $coverage[$q->id][(int) $item->rfq_line_id] = $item;
            }
        }

        $lines = $rfq->lines->map(function (PurchaseRfqLine $line) use ($quotes, $coverage): array {
            $cells = [];
            $best = null;

            foreach ($quotes as $q) {
                $item = $coverage[$q->id][(int) $line->id] ?? null;

                if ($item === null) {
                    $cells[$q->id] = null;

                    continue;
                }

                $cells[$q->id] = [
                    'quote_id' => (int) $q->id,
                    'unit_price' => (string) $item->unit_price,
                    'qty' => (string) $item->qty,
                    'total' => (string) $item->line_total,
                    'expired' => $q->isExpired(),
                ];

                if (! $q->isExpired() && ($best === null || $this->cents((float) $item->line_total) < $this->cents((float) $cells[$best]['total']))) {
                    $best = $q->id;
                }
            }

            return [
                'id' => (int) $line->id,
                'description' => $line->description,
                'qty' => (string) $line->qty,
                'requisition_item_id' => $line->requisition_item_id ? (int) $line->requisition_item_id : null,
                'cells' => $cells,
                'best_quote_id' => $best,
            ];
        })->values()->all();

        $rows = $quotes->map(function (PurchaseRfqQuote $q) use ($rfq, $coverage): array {
            $count = $rfq->lines()->count();
            $covered = count($coverage[$q->id] ?? []);

            return [
                'id' => (int) $q->id,
                'vendor_id' => (int) $q->vendor_id,
                'vendor' => $q->vendor?->name,
                'quote_ref' => $q->quote_ref,
                'total' => (string) $q->total_amount,
                'lines_quoted' => $covered,
                'lines_requested' => $count,
                'complete' => $count > 0 && $covered === $count,
                'expired' => $q->isExpired(),
                'lead_time_days' => $q->lead_time_days,
                'valid_until' => $q->valid_until?->toDateString(),
            ];
        })->values()->all();

        return [
            'lines' => $lines,
            'quotes' => $rows,
            'recommendation' => $this->recommend($rfq, $rows),
        ];
    }

    /**
     * Lowest *complete* valid total.
     *
     * A partial quote is excluded even when its total is smaller: awarding it would leave lines un-bought
     * and the requisition half-satisfied, which is exactly the "cheapest on paper" trap the round exists
     * to avoid. Ties break on the earlier due-by date, then on lead time, then on vendor id — so the
     * recommendation is reproducible, which is what makes the award defensible.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{quote_id:int,reason:string}|null
     */
    private function recommend(PurchaseRfq $rfq, array $rows): ?array
    {
        // `$this` inside the filter: a static closure would be a fatal error, and the cents conversion
        // is the comparison, so it cannot move out.
        $usable = array_values(array_filter($rows, fn (array $r): bool => $r['complete'] && ! $r['expired'] && $this->cents((float) $r['total']) > 0));

        if ($usable === []) {
            return null;
        }

        usort($usable, function (array $a, array $b) use ($rfq): int {
            $byTotal = $this->cents((float) $a['total']) <=> $this->cents((float) $b['total']);

            if ($byTotal !== 0) {
                return $byTotal;
            }

            $quoteA = $rfq->quotes->firstWhere('id', $a['id']);
            $quoteB = $rfq->quotes->firstWhere('id', $b['id']);
            $byValid = strcmp((string) ($quoteA?->valid_until?->toDateString() ?: '9999-12-31'), (string) ($quoteB?->valid_until?->toDateString() ?: '9999-12-31'));

            if ($byValid !== 0) {
                return $byValid;
            }

            return ((int) $a['vendor_id']) <=> ((int) $b['vendor_id']);
        });

        $winner = $usable[0];

        return [
            'quote_id' => (int) $winner['id'],
            'reason' => sprintf('Lowest complete, in-date total: %s (%s lines quoted).', $winner['total'], $winner['lines_quoted']),
        ];
    }

    /**
     * Award the round and produce the purchase orders.
     *
     * @param  list<array{rfq_line_id:int,quote_id:int}>  $picks  what to buy from whom, per line
     * @return list<int> the purchase order ids created — one per distinct vendor, so a split award is
     *                   visibly several orders rather than one confusing one
     */
    public function award(PurchaseRfq $rfq, array $picks, ?int $warehouseId = null): array
    {
        if ($rfq->awarded_quote_id !== null) {
            throw new \DomainException('This round has already been awarded.');
        }

        if ($rfq->status === 'cancelled') {
            throw new \DomainException('A cancelled round cannot be awarded.');
        }

        if ($picks === []) {
            throw new \DomainException('Award needs at least one chosen line.');
        }

        $requisition = PurchaseRequisition::find($rfq->requisition_id);
        $warehouse = $warehouseId ?? $requisition?->warehouse_id;

        // A purchase order line has to land somewhere, and a round raised without a requisition has no
        // answer to that question. Refusing here beats defaulting to "the first warehouse", which would
        // quietly stock a shop that never asked for anything.
        if (! $warehouse) {
            throw new \DomainException('This round has no requisition to inherit a receiving warehouse from — choose one.');
        }

        $rfq->loadMissing(['lines.requisitionItem', 'quotes.items', 'quotes.vendor']);

        $lines = $rfq->lines->keyBy('id');
        $quotes = $rfq->quotes->keyBy('id');
        $seen = [];
        $chosen = [];

        foreach ($picks as $pick) {
            $lineId = (int) ($pick['rfq_line_id'] ?? 0);
            $quoteId = (int) ($pick['quote_id'] ?? 0);

            if (isset($seen[$lineId])) {
                throw new \DomainException('A line can only be awarded once in the same call.');
            }

            $seen[$lineId] = true;

            $line = $lines[$lineId] ?? null;
            $quote = $quotes[$quoteId] ?? null;

            if ($line === null || $quote === null) {
                throw new \DomainException('Every award must name a line and a quote of this round.');
            }

            if ($quote->isWithdrawn()) {
                throw new \DomainException('That vendor withdrew the quote.');
            }

            if ($quote->isExpired()) {
                throw new \DomainException(sprintf(
                    '%s\'s quote expired on %s — get a fresh price rather than awarding an old one.',
                    $quote->vendor?->name ?: 'The vendor',
                    $quote->valid_until?->toDateString() ?: 'its own validity date',
                ));
            }

            $item = $quote->items->firstWhere('rfq_line_id', $lineId);

            if ($item === null) {
                throw new \DomainException('That quote does not price this line.');
            }

            $requisitionItem = $line->requisitionItem;

            if ($requisitionItem === null) {
                throw new \DomainException('This line is not tied to a requisition line, so there is nothing to order against — add the line to a requisition first.');
            }

            if ($requisitionItem->product_id === null) {
                throw new \DomainException(sprintf(
                    '"%s" has no product on it, and a purchase order line must. Pick one on the requisition and award again.',
                    $line->description,
                ));
            }

            // Never more than what is still outstanding, so a second round cannot re-buy a line that a
            // first one already covered.
            $qty = min(
                round((float) $item->qty, 2),
                round(max(0.0, (float) $requisitionItem->qty - (float) $requisitionItem->qty_ordered), 2),
            );

            if ($qty <= 0) {
                throw new \DomainException(sprintf('"%s" is already fully ordered.', $line->description));
            }

            $chosen[] = [
                'line' => $line,
                'quote' => $quote,
                'item' => $item,
                'requisition_item' => $requisitionItem,
                'qty' => $qty,
                'unit_price' => (float) $item->unit_price,
            ];
        }

        $orderService = app(PurchaseOrderService::class);

        return DB::transaction(function () use ($rfq, $chosen, $orderService, $requisition, $warehouse): array {
            /** @var array<int,PurchaseOrder> $ordersByVendor */
            $ordersByVendor = [];

            foreach ($chosen as $c) {
                $vendorId = (int) $c['quote']->vendor_id;

                if (! isset($ordersByVendor[$vendorId])) {
                    // `PurchaseOrderService::create()` is the real path: it mints `order_no`, defaults
                    // `status`, stamps `created_by` and computes the money. An award that inserted rows
                    // directly would be a second, weaker way to make a PO.
                    $ordersByVendor[$vendorId] = $orderService->create([
                        'vendor_id' => $vendorId,
                        'warehouse_id' => $warehouse,
                        'order_date' => now()->toDateString(),
                        'expected_date' => $requisition?->need_by?->toDateString(),
                        'requisition_id' => $requisition?->id,
                        'notes' => sprintf('Converted from %s / %s (%s).', $requisition?->req_no ?: 'a requisition', $rfq->rfq_no, (string) $c['quote']->quote_ref ?: 'a vendor quote'),
                        'items' => [],
                    ]);
                }

                $order = $ordersByVendor[$vendorId];

                $orderService->update($order, [
                    'items' => array_merge(
                        $order->items->map(fn ($i): array => [
                            'product_id' => $i->product_id,
                            'product_name' => $i->product_name,
                            'sku' => $i->sku,
                            'uom_id' => $i->uom_id,
                            'qty' => (float) $i->qty,
                            'unit_price' => (float) $i->unit_price,
                        ])->all(),
                        [[
                            'product_id' => $c['requisition_item']->product_id,
                            'qty' => $c['qty'],
                            'unit_price' => $c['unit_price'],
                        ]],
                    ),
                ]);

                PurchaseRfqAward::create([
                    'rfq_id' => $rfq->id,
                    'quote_id' => (int) $c['quote']->id,
                    'rfq_line_id' => (int) $c['line']->id,
                    'requisition_item_id' => (int) $c['requisition_item']->id,
                    'unit_price' => $c['unit_price'],
                    'qty' => $c['qty'],
                    'order_id' => $ordersByVendor[$vendorId]->id,
                ]);

                $c['requisition_item']->update([
                    'qty_ordered' => round((float) $c['requisition_item']->qty_ordered + $c['qty'], 2),
                ]);

                $ordersByVendor[$vendorId] = $order->fresh(['items']);
            }

            // Which quote the round "went to" is the one holding the most awarded value: with a split
            // award there is no single winner, and naming the biggest beats naming the first pick,
            // because the header field is what an audit reads to see whose paper won.
            $byQuote = [];

            foreach ($chosen as $c) {
                $id = (int) $c['quote']->id;
                $byQuote[$id] = ($byQuote[$id] ?? 0) + $this->cents($this->multiply($c['qty'], $c['unit_price']));
            }

            arsort($byQuote);
            $primaryId = (int) array_key_first($byQuote);

            $rfq->update([
                'awarded_quote_id' => $primaryId,
                'awarded_at' => now(),
                'awarded_by' => auth()->id(),
                'status' => 'closed',
            ]);

            if ($requisition !== null) {
                app(PurchaseRequisitionService::class)->attachOrder($requisition, array_values($ordersByVendor)[0]);
            }

            return array_map(static fn (PurchaseOrder $o): int => (int) $o->id, array_values($ordersByVendor));
        });
    }

    private function syncLines(PurchaseRfq $rfq, array $lines): void
    {
        $rfq->lines()->delete();

        foreach ($lines as $line) {
            PurchaseRfqLine::create([
                'rfq_id' => $rfq->id,
                'requisition_item_id' => $line['requisition_item_id'] ?? null,
                'description' => $line['description'] ?? '',
                'qty' => (float) ($line['qty'] ?? 0),
                'uom_id' => $line['uom_id'] ?? null,
            ]);
        }
    }

    private function query(DataTableQuery $dt): Builder
    {
        $q = PurchaseRfq::query()->with(['requisition', 'quotes', 'lines']);

        $this->applyDataTable(
            $q,
            $dt,
            ['purchase_rfqs.rfq_no', 'purchase_rfqs.subject'],
            [
                'rfq_no' => 'purchase_rfqs.rfq_no',
                'status' => 'purchase_rfqs.status',
                'due_on' => 'purchase_rfqs.due_on',
                'created_at' => 'purchase_rfqs.created_at',
            ],
            [
                'status' => fn (Builder $x, string $v) => $x->where('purchase_rfqs.status', $v),
                'requisition_id' => fn (Builder $x, string $v) => $x->where('purchase_rfqs.requisition_id', (int) $v),
            ],
        );

        return $q;
    }

    public function nextNo(): string
    {
        $pad = (int) config('purchase.code.pad', 5);
        $pref = config('purchase.code.rfq_prefix', 'RFQ-');

        return DB::transaction(function () use ($pref, $pad): string {
            $last = PurchaseRfq::where('rfq_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('rfq_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;

            return $pref.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function cents(float $v): int
    {
        return (int) round($v * 100);
    }

    private function centsToAmount(int $cents): float
    {
        return $cents / 100;
    }

    private function multiply(float $qty, float $price): float
    {
        $q = (int) round($qty * 1000);
        $p = (int) round($price * 100);

        return round($q * $p / 100000, 2);
    }
}
