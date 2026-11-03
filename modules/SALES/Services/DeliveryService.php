<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\DeliveryRepositoryInterface;
use Modules\SALES\Models\Delivery;

class DeliveryService
{
    public function __construct(private readonly DeliveryRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): Delivery { return $this->repo->findOrFail($id); }

    public function create(array $data): Delivery
    {
        return DB::transaction(function () use ($data): Delivery {
            $order = isset($data['order_id']) ? \Modules\SALES\Models\Order::find($data['order_id']) : null;
            if ($order && $order->status === 'cancelled') throw new \DomainException('Cannot deliver cancelled order');
            $data['delivery_no'] = $this->nextNo();
            $data['status'] = 'draft';
            $data['created_by'] = auth()->id();
            $items = $data['items'] ?? [];
            unset($data['items']);
            $delivery = Delivery::create($data);
            foreach ($items as $it) {
                $oi = isset($it['order_item_id']) ? \Modules\SALES\Models\OrderItem::find($it['order_item_id']) : null;
                $delivery->items()->create([
                    'order_item_id' => $oi?->id,
                    'product_id' => $it['product_id'],
                    'product_name' => $it['product_name'] ?? $oi?->product_name ?? \Modules\INVENTORY\Models\Product::find($it['product_id'])?->name ?? '—',
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
        if (! $delivery->isDraft()) throw new \DomainException('Only draft can be shipped');
        return DB::transaction(function () use ($delivery): Delivery {
            // deduct stock via inventory
            if (app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $items = $delivery->items->map(fn($it) => [
                    'product_id' => $it->product_id, 'variant_id' => $it->variant_id ?? null,
                    'warehouse_id' => $delivery->warehouse_id, 'qty' => (float) $it->qty,
                    'lot_no' => $it->lot_no, 'serial_no' => $it->serial_no,
                ])->toArray();
                $provider->deductStock($items, $delivery->delivery_no, 'sales_delivery');
            }
            // update order delivered qty
            if ($delivery->order_id) {
                $order = \Modules\SALES\Models\Order::find($delivery->order_id);
                if ($order) {
                    foreach ($delivery->items as $di) {
                        if ($di->order_item_id) {
                            \Modules\SALES\Models\OrderItem::where('id', $di->order_item_id)->increment('qty_delivered', (float) $di->qty);
                        }
                    }
                    // note: order status enum has no delivered state yet — keep qty_delivered for tracking; status stays confirmed/invoiced
                    // future: add migration to allow delivered/partially_delivered if needed
                    $order->refresh(); $order->load('items');
                }
            }
            $delivery->update(['status' => 'shipped', 'shipped_by' => auth()->id(), 'shipped_at' => now()]);
            return $delivery->fresh('items');
        });
    }

    public function cancel(Delivery $delivery): Delivery
    {
        if ($delivery->isShipped()) throw new \DomainException('Shipped delivery cannot be cancelled — use return');
        $delivery->update(['status' => 'cancelled']);
        return $delivery;
    }

    public function delete(Delivery $delivery): void
    {
        if ($delivery->isShipped()) throw new \DomainException('Shipped delivery cannot be deleted');
        $delivery->delete();
    }

    private function nextNo(): string
    {
        $last = Delivery::withTrashed()->orderByDesc('id')->value('delivery_no');
        $n = $last ? (int) preg_replace('/\D/', '', $last) : 0;
        return 'DN-'.str_pad((string)($n+1), 5, '0', STR_PAD_LEFT);
    }
}
