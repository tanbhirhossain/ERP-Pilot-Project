<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\OrderRepositoryInterface;
use Modules\SALES\Models\Order;

class OrderService
{
    public function __construct(private readonly OrderRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): Order { return $this->repo->findOrFail($id); }
    public function create(array $data): Order { return $this->repo->create($this->enrichTotals($data)); }
    public function update(Order $o, array $data): Order { return $this->repo->update($o, $this->enrichTotals($data)); }
    public function delete(Order $o): void { $this->repo->delete($o); }

    public function confirm(Order $order): Order
    {
        if (! $order->isConfirmable()) throw new \DomainException('Order not confirmable (must be draft).');
        return DB::transaction(function () use ($order): Order {
            // check stock availability via INVENTORY provider
            if (config('sales.stock.reserve_on_order', true) && app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $order->load('items');
                $items = $order->items->map(fn($it) => ['product_id' => $it->product_id, 'variant_id' => $it->variant_id, 'warehouse_id' => $order->warehouse_id, 'qty' => (float) $it->qty])->toArray();
                $provider->reserveStock($items, $order->order_no, 'sales_order');
            }
            $order->update(['status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now()]);
            return $order->fresh();
        });
    }

    public function cancel(Order $order): Order
    {
        if ($order->invoices()->exists()) throw new \DomainException('Order with invoices cannot be cancelled.');
        // release reserved if any
        if (app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
            $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
            $order->load('items');
            $items = $order->items->map(fn($it) => ['product_id' => $it->product_id, 'variant_id' => $it->variant_id, 'warehouse_id' => $order->warehouse_id, 'qty' => (float) $it->qty])->toArray();
            try { $provider->releaseReservation($items, $order->order_no); } catch (\Throwable $e) {}
        }
        $order->update(['status' => 'cancelled']);
        return $order->fresh();
    }

    private function enrichTotals(array $data): array
    {
        if (! isset($data['items']) || ! is_array($data['items'])) return $data;
        $subtotal = 0; $vat = 0; $discount = 0;
        foreach ($data['items'] as &$it) {
            if (empty($it['product_name']) && !empty($it['product_id'])) { $p = \Modules\INVENTORY\Models\Product::find($it['product_id']); if ($p) { $it['product_name'] = $p->name; $it['sku'] = $p->sku; $it['uom_id'] = $it['uom_id'] ?? $p->uom_id; } }
            $qty = (float) ($it['qty'] ?? 0); $price = (float) ($it['unit_price'] ?? 0);
            $discPct = (float) ($it['discount_pct'] ?? 0); $vatRate = (float) ($it['vat_rate'] ?? 0);
            $gross = $qty * $price; $discAmt = $gross * ($discPct / 100); $net = $gross - $discAmt; $vatAmt = $net * ($vatRate / 100);
            $it['discount_amount'] = $discAmt; $it['vat_amount'] = $vatAmt; $it['line_total'] = $net + $vatAmt;
            $subtotal += $gross; $discount += $discAmt; $vat += $vatAmt;
        }
        $data['subtotal'] = $subtotal; $data['discount_amount'] = $discount; $data['vat_amount'] = $vat; $data['total_amount'] = $subtotal - $discount + $vat;
        return $data;
    }
}
