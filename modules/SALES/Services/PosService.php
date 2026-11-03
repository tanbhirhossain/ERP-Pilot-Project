<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Interfaces\PosRepositoryInterface;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

class PosService
{
    public function __construct(private readonly PosRepositoryInterface $repo, private readonly InvoiceService $invoices) {}

    public function sessionsPaginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->sessionsPaginate($dt); }
    public function salesPaginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->salesPaginate($dt); }
    public function findSessionOrFail(int $id): PosSession { return $this->repo->findSessionOrFail($id); }
    public function findSaleOrFail(int $id): PosSale { return $this->repo->findSaleOrFail($id); }
    public function openSession(array $data): PosSession { return $this->repo->openSession($data); }
    public function closeSession(PosSession $s, array $data): PosSession { return $this->repo->closeSession($s, $data); }

    public function sell(PosSaleData $dto): PosSale
    {
        return DB::transaction(function () use ($dto): PosSale {
            // Build invoice-equivalent items with snapshots
            $items = [];
            $subtotal = 0; $vat = 0; $discount = 0;
            foreach ($dto->lines as $line) {
                $product = \Modules\INVENTORY\Models\Product::findOrFail($line->productId);
                $gross = $line->qty * $line->unitPrice;
                $disc = $gross * ($line->discountPct / 100);
                $net = $gross - $disc;
                $vatAmt = $net * ($line->vatRate / 100);
                $items[] = [
                    'product_id' => $line->productId, 'variant_id' => $line->variantId,
                    'product_name' => $product->name, 'sku' => $product->sku,
                    'qty' => $line->qty, 'unit_price' => $line->unitPrice,
                    'discount_pct' => $line->discountPct, 'discount_amount' => $disc,
                    'vat_rate' => $line->vatRate, 'vat_amount' => $vatAmt, 'line_total' => $net + $vatAmt,
                    'cost_price' => $product->cost_price,
                ];
                $subtotal += $gross; $discount += $disc; $vat += $vatAmt;
            }
            $total = $subtotal - $discount + $vat;
            // Payment mapping: cash keeps cash, bank/bkash/card go to bank, split is manual split
            $isBankLike = in_array($dto->paymentMethod, ['bank','bkash','card']);
            $cash = $dto->paymentMethod === 'split' ? $dto->cashAmount : ($dto->paymentMethod === 'cash' ? $total : 0);
            $bank = $dto->paymentMethod === 'split' ? $dto->bankAmount : ($isBankLike ? $total : 0);
            if ($dto->paymentMethod === 'split' && abs(($cash + $bank) - $total) > 0.01) {
                throw new \DomainException('Split amounts (cash '.number_format($cash,2).' + bank '.number_format($bank,2).') must equal total '.number_format($total,2).' BDT.');
            }

            // Create POS sale
            $sale = $this->repo->createSale([
                'session_id' => $dto->sessionId, 'customer_id' => $dto->customerId, 'customer_name' => $dto->customerName,
                'warehouse_id' => $dto->warehouseId, 'sale_date' => now()->toDateString(),
                'subtotal' => $subtotal, 'discount_amount' => $discount, 'vat_amount' => $vat, 'total_amount' => $total,
                'paid_amount' => $total, 'change_amount' => 0, 'payment_method' => $dto->paymentMethod,
                'cash_amount' => $cash, 'bank_amount' => $bank, 'bank_id' => $dto->bankId, 'status' => 'completed',
                'items' => $items,
            ]);

            // Also create + post sales invoice (for ledger) then link
            $invoice = $this->invoices->create([
                'customer_id' => $dto->customerId ?? \Modules\PARTNER\Models\Partner::where('type', 'customer')->value('id') ?? 1,
                'warehouse_id' => $dto->warehouseId, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
                'currency' => 'BDT', 'payment_method' => $dto->paymentMethod, 'bank_id' => $dto->bankId,
                'notes' => "POS {$sale->pos_no}",
                'items' => $items,
            ]);
            $invoice = $this->invoices->post($invoice);
            $sale->update(['invoice_id' => $invoice->id]);

            // For cash, cash_amount already handled via invoice journal (RV); POS session aggregates updated in repo

            return $sale->fresh(['items', 'invoice']);
        });
    }

    public function void(PosSale $sale): PosSale
    {
        if ($sale->status === 'void') throw new \DomainException('Already voided.');
        return DB::transaction(function () use ($sale): PosSale {
            // restock
            if (app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $sale->load('items');
                $items = $sale->items->map(fn($it) => ['product_id' => $it->product_id, 'variant_id' => $it->variant_id, 'warehouse_id' => $sale->warehouse_id, 'qty' => (float) $it->qty])->toArray();
                $provider->restock($items, $sale->pos_no, 'pos_void');
            }
            // void invoice if exists
            if ($sale->invoice_id && app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                try {
                    $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                    $sale->load('invoice');
                    if ($sale->invoice?->journal_id) $posting->voidJournal($sale->invoice->journal_id, "Void POS {$sale->pos_no}");
                } catch (\Throwable $e) {}
            }
            $sale->update(['status' => 'void']);
            return $sale->fresh();
        });
    }
}
