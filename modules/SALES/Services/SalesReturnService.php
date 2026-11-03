<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\SalesReturnRepositoryInterface;
use Modules\SALES\Models\SalesReturn;

class SalesReturnService
{
    public function __construct(private readonly SalesReturnRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): SalesReturn { return $this->repo->findOrFail($id); }

    public function create(array $data): SalesReturn
    {
        return DB::transaction(function () use ($data): SalesReturn {
            $invoice = \Modules\SALES\Models\Invoice::findOrFail($data['invoice_id']);
            if (! $invoice->isPosted()) throw new \DomainException('Only posted invoices can be returned');
            $data['return_no'] = $this->nextNo();
            $data['customer_id'] = $invoice->customer_id;
            $data['warehouse_id'] = $data['warehouse_id'] ?? $invoice->warehouse_id;
            $data['status'] = 'draft';
            $data['created_by'] = auth()->id();
            $items = $data['items'] ?? [];
            unset($data['items']);
            // compute totals
            $subtotal=0; $vat=0;
            foreach ($items as &$it) {
                $invItem = \Modules\SALES\Models\InvoiceItem::find($it['invoice_item_id']);
                $qty = (float) $it['qty']; $price = (float) ($invItem->unit_price ?? $it['unit_price'] ?? 0);
                $it['product_id'] = $invItem->product_id; $it['unit_price']=$price; $it['line_total']=$qty*$price;
                $vatRate = (float) ($invItem->vat_rate ?? 0); $it['vat_rate']=$vatRate; $it['vat_amount']=$it['line_total']*($vatRate/100);
                $subtotal += $it['line_total']; $vat += $it['vat_amount'];
            }
            $data['subtotal']=$subtotal; $data['vat_amount']=$vat; $data['total_amount']=$subtotal+$vat;
            $ret = SalesReturn::create($data);
            foreach ($items as $it) $ret->items()->create($it);
            return $ret->fresh('items');
        });
    }

    public function approve(SalesReturn $ret): SalesReturn
    {
        if (! $ret->isDraft()) throw new \DomainException('Only draft can be approved');
        return DB::transaction(function () use ($ret): SalesReturn {
            $ret->load(['items.product','invoice']);
            // 1) restock resalable items
            $resalable = $ret->items->where('condition','resalable');
            if ($resalable->isNotEmpty() && app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $items = $resalable->map(fn($it)=>[
                    'product_id'=>$it->product_id,'warehouse_id'=>$ret->warehouse_id,'qty'=>(float)$it->qty,
                    'unit_cost'=>(float)($it->invoiceItem->cost_price ?? $it->unit_price),'lot_no'=>$it->lot_no,
                ])->toArray();
                $provider->restock($items, $ret->return_no, 'sales_return');
                $ret->update(['is_restocked'=>true]);
            }
            // 2) credit note journal: Sales Return Dr, VAT Dr, AR Cr, plus COGS reversal
            $journalId=null;
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                $salesCode = config('accounts.defaults.sales_code','4001');
                $arCode = config('accounts.defaults.receivable_code','1100');
                $cogsCode='5001'; $invCode=config('accounts.defaults.inventory_code','1200');
                $vatCode=config('accounts.defaults.vat_payable_code','2110');
                $salesId=$posting->accountIdByCode($salesCode); $arId=$posting->accountIdByCode($arCode);
                $cogsId=$posting->accountIdByCode($cogsCode); $invId=$posting->accountIdByCode($invCode); $vatId=$posting->accountIdByCode($vatCode);
                if ($arId && $salesId) {
                    $lines=[];
                    $total = (float)$ret->total_amount; $subtotal=(float)$ret->subtotal; $vat=(float)$ret->vat_amount;
                    // reverse sales: Sales Return Dr (we debit Sales, effectively negative revenue)
                    $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId:$salesId, debit:$subtotal, description:"Sales return {$ret->return_no} — {$ret->invoice->invoice_no}");
                    if ($vat>0 && $vatId) $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId:$vatId, debit:$vat, description:"VAT reversal {$ret->return_no}");
                    $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId:$arId, credit:$total, description:"AR credit {$ret->return_no}");
                    // COGS reversal if restocked
                    $totalCost = $ret->items->sum(fn($it)=>(float)($it->invoiceItem->cost_price ?? $it->unit_price)*(float)$it->qty);
                    if ($totalCost>0 && $cogsId && $invId && $ret->is_restocked) {
                        $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId:$invId, debit:$totalCost, description:"Inventory return {$ret->return_no}");
                        $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId:$cogsId, credit:$totalCost, description:"COGS reversal {$ret->return_no}");
                    }
                    $dto = new \Modules\ACCOUNTS\DTO\JournalData(journalDate:$ret->return_date->format('Y-m-d'), type:'JV', referenceNo:$ret->return_no, narration:"Sales return {$ret->return_no} for {$ret->invoice->invoice_no}", lines:$lines, partnerId:$ret->customer_id, partnerType:'customer', sourceType:'sales_return', sourceId:$ret->id);
                    $journal=$posting->postJournal($dto); $journalId=$journal->id;
                }
            }
            $ret->update(['status'=>'approved','journal_id'=>$journalId,'approved_by'=>auth()->id(),'approved_at'=>now()]);
            return $ret->fresh(['items','journal']);
        });
    }

    public function cancel(SalesReturn $ret): SalesReturn { if ($ret->status !== 'draft') throw new \DomainException('Only draft can be cancelled'); $ret->update(['status'=>'cancelled']); return $ret; }
    public function delete(SalesReturn $ret): void { if (! $ret->isDraft()) throw new \DomainException('Only draft can be deleted'); $ret->delete(); }

    private function nextNo(): string { $last = SalesReturn::withTrashed()->orderByDesc('id')->value('return_no'); $n=$last ? (int)preg_replace('/\D/','',$last):0; return 'SR-'.str_pad((string)($n+1),5,'0',STR_PAD_LEFT); }
}
