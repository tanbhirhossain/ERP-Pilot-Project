<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Services\ValuationService;
use Modules\SALES\Interfaces\DeliveryRepositoryInterface;
use Modules\SALES\Models\Delivery;
use Modules\SALES\Models\Order;
use Modules\SALES\Models\OrderItem;

class DeliveryService
{
    public function __construct(private readonly DeliveryRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): Delivery
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): Delivery
    {
        return DB::transaction(function () use ($data): Delivery {
            $order = isset($data['order_id']) ? Order::find($data['order_id']) : null;
            if ($order && $order->status === 'cancelled') {
                throw new \DomainException('Cannot deliver cancelled order');
            }
            $data['delivery_no'] = $this->nextNo();
            $data['status'] = 'draft';
            $data['created_by'] = auth()->id();
            $items = $data['items'] ?? [];
            unset($data['items']);
            $delivery = Delivery::create($data);
            foreach ($items as $it) {
                $oi = isset($it['order_item_id']) ? OrderItem::find($it['order_item_id']) : null;
                if ($oi !== null && $order !== null && (int) $oi->order_id !== (int) $order->id) {
                    throw new \DomainException("Delivery line \"{$oi->product_name}\" belongs to a different order — a challan ships its own order's lines.");
                }
                if ((float) ($it['qty'] ?? 0) <= 0) {
                    throw new \DomainException('Delivery quantities must be positive — goods coming back travel as a sales return.');
                }
                $delivery->items()->create([
                    'order_item_id' => $oi?->id,
                    'product_id' => $it['product_id'],
                    'product_name' => $it['product_name'] ?? $oi?->product_name ?? Product::find($it['product_id'])?->name ?? '—',
                    'sku' => $it['sku'] ?? $oi?->sku ?? null,
                    'qty' => (float) $it['qty'],
                    'lot_no' => $it['lot_no'] ?? null,
                    'serial_no' => $it['serial_no'] ?? null,
                    'notes' => $it['notes'] ?? null,
                ]);
            }

            return $delivery->fresh('items');
        });
    }

    public function ship(Delivery $delivery): Delivery
    {
        if (! $delivery->isDraft()) {
            throw new \DomainException('Only draft can be shipped');
        }

        return DB::transaction(function () use ($delivery): Delivery {
            $delivery->loadMissing(['items.product', 'order']);
            if ($delivery->order && $delivery->order->status === 'cancelled') {
                throw new \DomainException("Order {$delivery->order->order_no} is cancelled — its deliveries cannot ship.");
            }
            if ($delivery->items->isEmpty()) {
                throw new \DomainException("Delivery {$delivery->delivery_no} has no lines — there is nothing to ship.");
            }

            // Deduct what this challan newly takes off the shelf: units already invoiced left at
            // invoice-post, so shipping them again would take the same goods off twice. This is the
            // mirror of InvoiceService, which deducts beyond what is shipped — together the two
            // take each unit off exactly once no matter which posts first.
            $invoicedPool = [];
            $shipRoom = [];
            if ($delivery->order_id) {
                $orderLines = OrderItem::where('order_id', $delivery->order_id)->lockForUpdate()->get();
                foreach ($orderLines as $ol) {
                    $invoicedPool[$ol->id] = (float) $ol->qty_invoiced;
                }
                // Read after the lock above: a challan shipping in the same instant holds these
                // rows, so its shipped quantities land before this sum is taken — two challans
                // cannot both spend the same unshipped units.
                $shippedSums = DB::table('sales_delivery_items as di')
                    ->join('sales_deliveries as d', 'd.id', '=', 'di.delivery_id')
                    ->where('d.order_id', $delivery->order_id)->whereIn('d.status', ['shipped', 'delivered'])
                    ->whereNotNull('di.order_item_id')
                    ->groupBy('di.order_item_id')->selectRaw('di.order_item_id AS order_item_id, SUM(di.qty) AS qty')
                    ->pluck('qty', 'order_item_id');
                foreach ($orderLines as $ol) {
                    $shipRoom[$ol->id] = (float) $ol->qty - (float) ($shippedSums[$ol->id] ?? 0);
                }
            }
            $deductItems = [];
            $releaseItems = [];
            foreach ($delivery->items as $di) {
                $qty = (float) $di->qty;
                if ($qty <= 0) {
                    throw new \DomainException("Delivery {$delivery->delivery_no} line \"{$di->product_name}\" has quantity {$di->qty} — shipped quantities must be positive.");
                }
                // A challan ships what the order still owes: anything beyond is goods the customer
                // never ordered, deducted off the shelf with no sale behind them. The invoiced pool
                // below only decides *whether* the units leave now — this decides whether they may
                // leave at all.
                if ($di->order_item_id && array_key_exists($di->order_item_id, $shipRoom)) {
                    if ($qty - $shipRoom[$di->order_item_id] > 0.0001) {
                        throw new \DomainException(sprintf(
                            'Delivery %s ships %.2f of "%s" but order %s only has %.2f unshipped on that line. The rest already left on another challan.',
                            $delivery->delivery_no, $qty, $di->product_name,
                            $delivery->order?->order_no ?? $delivery->order_id, max(0.0, $shipRoom[$di->order_item_id])
                        ));
                    }
                    $shipRoom[$di->order_item_id] -= $qty;
                }
                $deductQty = $qty;
                if ($di->order_item_id && array_key_exists($di->order_item_id, $invoicedPool)) {
                    $covered = min($invoicedPool[$di->order_item_id], $qty);
                    $invoicedPool[$di->order_item_id] -= $covered;
                    $deductQty = $qty - $covered;
                }
                $product = $di->product ?? Product::find($di->product_id);
                if ($product !== null && $product->type === 'service') {
                    $deductQty = 0.0;
                }

                // Capital assets leave by disposal, not by challan: there is no shelf quantity to
                // carry out, so a delivery line would move nothing while claiming it did.
                if ($product !== null && $product->type === 'asset') {
                    throw new \DomainException(
                        "Delivery {$delivery->delivery_no} line \"{$di->product_name}\" is a fixed asset — assets leave the business by disposal through the asset register, not by challan."
                    );
                }
                if ($deductQty > 0.0001) {
                    $deductItems[] = [
                        'product_id' => $di->product_id, 'variant_id' => $di->variant_id ?? null,
                        'warehouse_id' => $delivery->warehouse_id, 'qty' => $deductQty,
                        'unit_cost' => $this->snapshotCost($product, $di->variant_id ?? null, $delivery->warehouse_id, $deductQty),
                        'lot_no' => $di->lot_no, 'serial_no' => $di->serial_no,
                    ];
                    // Release exactly what left: the order's reservation covered these units until
                    // now. The old code never released anything, so fulfilled orders kept their
                    // stock reserved forever and the available-to-sell understated permanently.
                    if ($di->order_item_id) {
                        $releaseItems[] = [
                            'product_id' => $di->product_id, 'variant_id' => $di->variant_id ?? null,
                            'warehouse_id' => $delivery->warehouse_id, 'qty' => $deductQty,
                        ];
                    }
                }
            }

            // deduct stock via inventory
            if ($deductItems !== [] && app()->bound(InventoryStockProviderInterface::class)) {
                $provider = app(InventoryStockProviderInterface::class);
                $provider->deductStock($deductItems, $delivery->delivery_no, 'sales_delivery');
                if ($releaseItems !== []) {
                    $provider->releaseReservation($releaseItems, $delivery->delivery_no);
                }
            }
            // update order delivered qty
            if ($delivery->order_id) {
                $order = Order::find($delivery->order_id);
                if ($order) {
                    foreach ($delivery->items as $di) {
                        if ($di->order_item_id) {
                            OrderItem::where('id', $di->order_item_id)->increment('qty_delivered', (float) $di->qty);
                        }
                    }
                    // note: order status enum has no delivered state yet — keep qty_delivered for tracking; status stays confirmed/invoiced
                    // future: add migration to allow delivered/partially_delivered if needed
                    $order->refresh();
                    $order->load('items');
                }
            }
            $delivery->update(['status' => 'shipped', 'shipped_by' => auth()->id(), 'shipped_at' => now()]);

            return $delivery->fresh('items');
        });
    }

    public function cancel(Delivery $delivery): Delivery
    {
        if ($delivery->isShipped()) {
            throw new \DomainException('Shipped delivery cannot be cancelled — use return');
        }
        $delivery->update(['status' => 'cancelled']);

        return $delivery;
    }

    public function delete(Delivery $delivery): void
    {
        if ($delivery->isShipped()) {
            throw new \DomainException('Shipped delivery cannot be deleted');
        }
        $delivery->delete();
    }

    /**
     * What one unit leaving on this challan costs, for the movement ledger: FIFO layers for a
     * FIFO product, otherwise the warehouse average. A miss records zero — the stock valuation
     * itself relieves at average regardless — but a value the shelf never held must never be
     * invented here.
     */
    private function snapshotCost(?Product $product, ?int $variantId, int $warehouseId, float $qty): float
    {
        if ($product === null || ! app()->bound(InventoryValuationProviderInterface::class)) {
            return 0.0;
        }
        if ($product->valuation_method === 'fifo' && app()->bound(ValuationService::class)) {
            $fifo = app(ValuationService::class)->getFifoCost($product->id, $variantId, $warehouseId, $qty);
            if ((float) ($fifo['unit_cost'] ?? 0) > 0.001) {
                return (float) $fifo['unit_cost'];
            }
        }

        return app(InventoryValuationProviderInterface::class)->getUnitCost($product->id, $variantId, $warehouseId);
    }

    private function nextNo(): string
    {
        $last = Delivery::withTrashed()->orderByDesc('id')->value('delivery_no');
        $n = $last ? (int) preg_replace('/\D/', '', $last) : 0;

        return 'DN-'.str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }
}
