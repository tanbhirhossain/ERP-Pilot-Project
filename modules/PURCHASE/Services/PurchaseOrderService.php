<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Services\ApprovalService;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Product;
use Modules\PURCHASE\Interfaces\PurchaseOrderRepositoryInterface;
use Modules\PURCHASE\Models\PurchaseOrder;

class PurchaseOrderService
{
    public function __construct(private readonly PurchaseOrderRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): PurchaseOrder
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return $this->repo->create($this->enrichTotals($data));
    }

    public function update(PurchaseOrder $o, array $data): PurchaseOrder
    {
        return $this->repo->update($o, $this->enrichTotals($data));
    }

    public function delete(PurchaseOrder $o): void
    {
        $this->repo->delete($o);
    }

    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->isDraft()) {
            throw new \DomainException('Only draft can be confirmed.');
        }
        $threshold = (float) config('purchase.workflow.approval_threshold', 50000);
        $total = (float) $order->total_amount;
        if ($total > $threshold) {
            // >50k requires explicit approve permission (manager)
            if (! auth()->user()?->can('purchase.orders.approve')) {
                throw new \DomainException('PO total '.number_format($total, 2).' exceeds threshold '.number_format($threshold, 2).' — approval required (purchase.orders.approve).');
            }
        }

        // Generic approval matrix (Phase 1). No-op until an admin activates a
        // policy for purchase_order; runs before the confirm write so a pending
        // request + approver notification survive the refusal.
        app(ApprovalService::class)
            ->assertAllowed('purchase_order', $order->id, $total, $order->order_no);

        $order->update(['status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now()]);

        return $order->fresh();
    }

    public function approve(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->isDraft() && $order->status !== 'sent') {
            throw new \DomainException('Only draft/sent PO can be approved.');
        }
        if (! auth()->user()?->can('purchase.orders.approve')) {
            throw new \DomainException('Missing purchase.orders.approve permission.');
        }
        $order->update(['status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now()]);

        return $order->fresh();
    }

    public function send(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->isDraft()) {
            throw new \DomainException('Only draft can be sent.');
        }
        $order->update(['status' => 'sent']);

        return $order->fresh();
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status === 'received') {
            throw new \DomainException('Received PO cannot be cancelled.');
        }
        if ($order->status === 'cancelled') {
            throw new \DomainException('Already cancelled.');
        }
        $order->update(['status' => 'cancelled']);

        return $order->fresh();
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
            $it['discount_amount'] = $discAmt;
            $it['vat_amount'] = $vatAmt;
            $it['line_total'] = $net + $vatAmt;
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
