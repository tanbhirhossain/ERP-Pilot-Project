<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Interfaces\VendorBillRepositoryInterface;
use Modules\PURCHASE\Models\VendorBill;

class VendorBillService
{
    public function __construct(private readonly VendorBillRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): VendorBill { return $this->repo->findOrFail($id); }
    public function create(array $data): VendorBill { return $this->repo->create($this->enrichTotals($data)); }
    public function update(VendorBill $b, array $data): VendorBill { return $this->repo->update($b, $this->enrichTotals($data)); }
    public function delete(VendorBill $b): void { $this->repo->delete($b); }

    public function post(VendorBill $bill): VendorBill
    {
        if (! $bill->isDraft()) throw new \DomainException('Only draft can be posted.');
        return DB::transaction(function () use ($bill): VendorBill {
            $bill->load(['items.product','vendor']);
            $journalId = null;
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                $apCode = config('accounts.defaults.payable_code', '2100');
                $invCode = config('accounts.defaults.inventory_code', '1200');
                $vatCode = config('accounts.defaults.vat_input_code', config('accounts.defaults.vat_payable_code', '1330'));
                $expCode = config('accounts.defaults.purchase_code', '5100');
                $apId = $posting->accountIdByCode($apCode);
                $invId = $posting->accountIdByCode($invCode);
                $vatId = $posting->accountIdByCode($vatCode);
                $expId = $posting->accountIdByCode($expCode);

                if ($apId) {
                    $lines = [];
                    $total = (float) $bill->total_amount;
                    $subtotal = (float) $bill->subtotal - (float) $bill->discount_amount;
                    $vat = (float) $bill->vat_amount;
                    $hasGrn = $bill->receipt_id && $bill->receipt;
                    $grniId = $posting->accountIdByCode('2115');
                    if ($hasGrn && $grniId) {
                        // GRN already hit Inventory/GRNI — Bill reverses GRNI to AP
                        $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $grniId, debit: $subtotal, description: "GRNI reversal {$bill->bill_no} — GRN {$bill->receipt->receipt_no}");
                        if ($vat > 0 && $vatId) $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $vatId, debit: $vat, description: "VAT input {$bill->bill_no}");
                        $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $apId, credit: $total, description: "AP {$bill->bill_no} - {$bill->vendor?->name}");
                    } else {
                        $useInventory = $hasGrn && $invId;
                        $debitAccount = $useInventory ? $invId : ($expId ?? $invId);
                        if ($debitAccount) {
                            $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $debitAccount, debit: $subtotal, description: "Purchase {$bill->bill_no} - {$bill->vendor?->name}");
                            if ($vat > 0 && $vatId) $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $vatId, debit: $vat, description: "VAT input {$bill->bill_no}");
                            $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $apId, credit: $total, description: "AP {$bill->bill_no} - {$bill->vendor?->name}");
                        }
                    }
                    if (! empty($lines)) {
                        $dto = new \Modules\ACCOUNTS\DTO\JournalData(journalDate: $bill->bill_date->format('Y-m-d'), type: 'PV', referenceNo: $bill->bill_no, narration: "Vendor Bill {$bill->bill_no}", lines: $lines, partnerId: $bill->vendor_id, partnerType: 'vendor', sourceType: 'purchase_bill', sourceId: $bill->id);
                        $journal = $posting->postJournal($dto);
                        $journalId = $journal->id;
                    }
                }
            }
            $bill->update(['status'=>'posted','journal_id'=>$journalId,'posted_by'=>auth()->id(),'posted_at'=>now()]);
            return $bill->fresh(['items','journal']);
        });
    }

    public function void(VendorBill $bill): VendorBill
    {
        if (! $bill->isPosted()) throw new \DomainException('Only posted bill can be voided.');
        if ((float) $bill->paid_amount > 0.001) throw new \DomainException('Paid bill cannot be voided — reverse payment first.');
        return DB::transaction(function () use ($bill): VendorBill {
            if ($bill->journal_id && app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                try { $posting->voidJournal($bill->journal_id, "Void bill {$bill->bill_no}"); } catch (\Throwable $e) {}
            }
            $bill->update(['status'=>'cancelled']);
            return $bill->fresh();
        });
    }

    public function recordPayment(VendorBill $bill, array $data): \Modules\PURCHASE\Models\PurchasePayment
    {
        if (! $bill->isPosted()) throw new \DomainException('Only posted bills can receive payments.');
        return DB::transaction(function () use ($bill, $data): \Modules\PURCHASE\Models\PurchasePayment {
            $payNo = 'PP-' . str_pad((string) (\Modules\PURCHASE\Models\PurchasePayment::max('id') + 1), 5, '0', STR_PAD_LEFT);
            $payment = \Modules\PURCHASE\Models\PurchasePayment::create($data + ['payment_no'=>$payNo,'bill_id'=>$bill->id,'vendor_id'=>$bill->vendor_id,'created_by'=>auth()->id()]);
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                $apCode = config('accounts.defaults.payable_code','2100');
                $cashCode = config('accounts.defaults.cash_code','1001');
                $apId = $posting->accountIdByCode($apCode);
                $cashId = $posting->accountIdByCode($cashCode);
                if ($data['bank_id'] ?? null) {
                    $bank = \Modules\ACCOUNTS\Models\Bank::find($data['bank_id']);
                    $cashId = $bank?->account_id ?? $cashId;
                }
                if ($cashId && $apId) {
                    $dto = new \Modules\ACCOUNTS\DTO\JournalData(journalDate: $data['payment_date'], type: 'PV', referenceNo: $payment->payment_no, narration: "Payment {$payment->payment_no} for {$bill->bill_no}", lines: [
                        new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $apId, debit: (float)$data['amount']),
                        new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $cashId, credit: (float)$data['amount']),
                    ], partnerId: $bill->vendor_id, partnerType: 'vendor', sourceType: 'purchase_payment', sourceId: $payment->id);
                    $j = $posting->postJournal($dto);
                    $payment->update(['journal_id'=>$j->id]);
                }
            }
            $bill->increment('paid_amount', (float)$data['amount']);
            $fresh = $bill->fresh();
            $fresh->update(['payment_status'=> (float)$fresh->paid_amount >= (float)$fresh->total_amount ? 'paid' : 'partial']);
            return $payment;
        });
    }

    private function enrichTotals(array $data): array
    {
        if (! isset($data['items']) || ! is_array($data['items'])) return $data;
        $subtotal=0; $vat=0; $discount=0;
        foreach ($data['items'] as &$it) {
            if (empty($it['product_name']) && ! empty($it['product_id'])) {
                $p = \Modules\INVENTORY\Models\Product::find($it['product_id']);
                if ($p) { $it['product_name']=$p->name; $it['sku']=$p->sku; }
            }
            $qty=(float)($it['qty']??0); $price=(float)($it['unit_price']??0);
            $discPct=(float)($it['discount_pct']??0); $vatRate=(float)($it['vat_rate']??0);
            $gross=$qty*$price; $discAmt=$gross*($discPct/100); $net=$gross-$discAmt; $vatAmt=$net*($vatRate/100);
            $it['discount_amount']=$discAmt; $it['vat_amount']=$vatAmt; $it['line_total']=$net+$vatAmt;
            $subtotal+=$gross; $discount+=$discAmt; $vat+=$vatAmt;
        }
        $data['subtotal']=$subtotal; $data['discount_amount']=$discount; $data['vat_amount']=$vat; $data['total_amount']=$subtotal-$discount+$vat;
        return $data;
    }
}
