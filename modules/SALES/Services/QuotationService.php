<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Product;
use Modules\SALES\Interfaces\OrderRepositoryInterface;
use Modules\SALES\Interfaces\QuotationRepositoryInterface;
use Modules\SALES\Models\Order;
use Modules\SALES\Models\Quotation;

class QuotationService
{
    public function __construct(private readonly QuotationRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): Quotation
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): Quotation
    {
        return $this->repo->create($this->enrichTotals($data));
    }

    public function update(Quotation $q, array $data): Quotation
    {
        return $this->repo->update($q, $this->enrichTotals($data));
    }

    public function delete(Quotation $q): void
    {
        $this->repo->delete($q);
    }

    public function send(Quotation $q): Quotation
    {
        if (! $q->isDraft()) {
            throw new \DomainException('Only draft can be sent.');
        }
        $q->update(['status' => 'sent']);

        return $q->fresh();
    }

    public function accept(Quotation $q): Quotation
    {
        if (! in_array($q->status, ['draft', 'sent'])) {
            throw new \DomainException('Only draft/sent can be accepted.');
        }
        $q->update(['status' => 'accepted', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return $q->fresh();
    }

    public function reject(Quotation $q): Quotation
    {
        $q->update(['status' => 'rejected']);

        return $q->fresh();
    }

    public function convertToOrder(Quotation $q, int $warehouseId, OrderRepositoryInterface $orders): Order
    {
        if ($q->converted_order_id) {
            throw new \DomainException('Already converted.');
        }
        if (! in_array($q->status, ['accepted', 'sent', 'draft'])) {
            throw new \DomainException('Only accepted/sent quotations can be converted.');
        }

        return DB::transaction(function () use ($q, $warehouseId, $orders): Order {
            $q->load('items');
            $order = $orders->create([
                'quotation_id' => $q->id, 'customer_id' => $q->customer_id, 'warehouse_id' => $warehouseId,
                'price_list_id' => $q->price_list_id, 'order_date' => now()->toDateString(), 'currency' => $q->currency,
                'subtotal' => $q->subtotal, 'discount_amount' => $q->discount_amount, 'vat_amount' => $q->vat_amount, 'total_amount' => $q->total_amount,
                'status' => 'draft',
                'items' => $q->items->map(fn ($it) => [
                    'product_id' => $it->product_id, 'variant_id' => $it->variant_id, 'product_name' => $it->product_name, 'sku' => $it->sku,
                    'uom_id' => $it->uom_id, 'qty' => $it->qty, 'unit_price' => $it->unit_price, 'discount_pct' => $it->discount_pct,
                    'discount_amount' => $it->discount_amount, 'vat_rate' => $it->vat_rate, 'vat_amount' => $it->vat_amount, 'line_total' => $it->line_total,
                ])->toArray(),
            ]);
            $q->update(['status' => 'converted', 'converted_order_id' => $order->id]);

            return $order;
        });
    }

    private function enrichTotals(array $data): array
    {
        if (! isset($data['items']) || ! is_array($data['items'])) {
            return $data;
        }
        $subtotal = 0;
        $vat = 0;
        $discount = 0;
        foreach ($data['items'] as &$it) {
            if (empty($it['product_name']) && ! empty($it['product_id'])) {
                $p = Product::find($it['product_id']);
                if ($p) {
                    $it['product_name'] = $p->name;
                    $it['sku'] = $p->sku;
                    $it['uom_id'] = $it['uom_id'] ?? $p->uom_id;
                }
            }
            $qty = (float) ($it['qty'] ?? 0);
            $price = (float) ($it['unit_price'] ?? 0);
            $discPct = (float) ($it['discount_pct'] ?? 0);
            $vatRate = (float) ($it['vat_rate'] ?? 0);
            $gross = $qty * $price;
            $discAmt = $gross * ($discPct / 100);
            $net = $gross - $discAmt;
            $vatAmt = $net * ($vatRate / 100);
            $lineTotal = $net + $vatAmt;
            $it['discount_amount'] = $discAmt;
            $it['vat_amount'] = $vatAmt;
            $it['line_total'] = $lineTotal;
            $subtotal += $gross;
            $discount += $discAmt;
            $vat += $vatAmt;
        }
        $data['subtotal'] = $subtotal;
        $data['discount_amount'] = $discount;
        $data['vat_amount'] = $vat;
        $data['total_amount'] = $subtotal - $discount + $vat;

        return $data;
    }
}
