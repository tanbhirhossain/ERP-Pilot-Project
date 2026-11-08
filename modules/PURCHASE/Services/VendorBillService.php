<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Support\ChartOfAccounts;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Product;
use Modules\PURCHASE\Interfaces\VendorBillRepositoryInterface;
use Modules\PURCHASE\Models\PurchasePayment;
use Modules\PURCHASE\Models\VendorBill;

class VendorBillService
{
    public function __construct(private readonly VendorBillRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): VendorBill
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): VendorBill
    {
        return $this->repo->create($this->enrichTotals($data));
    }

    public function update(VendorBill $b, array $data): VendorBill
    {
        return $this->repo->update($b, $this->enrichTotals($data));
    }

    public function delete(VendorBill $b): void
    {
        $this->repo->delete($b);
    }

    public function post(VendorBill $bill): VendorBill
    {
        if (! $bill->isDraft()) {
            throw new \DomainException('Only draft can be posted.');
        }

        return DB::transaction(function () use ($bill): VendorBill {
            $bill->load(['items.product', 'vendor']);
            $journalId = null;
            if (app()->bound(AccountsPostingProviderInterface::class)) {
                $posting = app(AccountsPostingProviderInterface::class);
                $purpose = "vendor bill {$bill->bill_no}";
                $apCode = config('accounts.defaults.payable_code', '2100');
                $invCode = config('accounts.defaults.inventory_code', '1200');
                $vatCode = config('accounts.defaults.vat_input_code', config('accounts.defaults.vat_payable_code', '1330'));
                $expCode = config('accounts.defaults.purchase_code', '5100');

                // A missing AP account used to skip this whole block and fall through to the
                // status update below, so the bill was marked posted with no ledger entry at all.
                $apId = ChartOfAccounts::requireId($posting, $apCode, $purpose);

                $total = (float) $bill->total_amount;
                $subtotal = (float) $bill->subtotal - (float) $bill->discount_amount;
                $vat = (float) $bill->vat_amount;
                $hasGrn = (bool) ($bill->receipt_id && $bill->receipt);

                if ($hasGrn) {
                    // GRN already hit Inventory/GRNI — the bill reverses GRNI to AP. GRNI is
                    // required, not optional: the old `$hasGrn && $grniId` test fell through to
                    // the else branch on a miss and debited Inventory a second time for stock the
                    // receipt had already booked.
                    $debitId = ChartOfAccounts::requireId($posting, config('accounts.defaults.grni_code', '2115'), $purpose);
                    $debitDescription = "GRNI reversal {$bill->bill_no} — GRN {$bill->receipt->receipt_no}";
                } else {
                    // No receipt: expense the purchase, or capitalise it into Inventory when the
                    // chart carries no purchase account. Either is acceptable; neither is not.
                    $debitId = ChartOfAccounts::firstExistingId($posting, $purpose, $expCode, $invCode);
                    $debitDescription = "Purchase {$bill->bill_no} - {$bill->vendor?->name}";
                }

                $lines = [
                    new JournalLineData(accountId: $debitId, debit: $subtotal, description: $debitDescription),
                ];
                if ($vat > 0) {
                    // Required whenever VAT is actually charged. Dropping this leg used to produce
                    // a journal that could not balance, which threw — but reported an arithmetic
                    // mismatch instead of naming the account that was missing.
                    $vatId = ChartOfAccounts::requireId($posting, $vatCode, $purpose);
                    $lines[] = new JournalLineData(accountId: $vatId, debit: $vat, description: "VAT input {$bill->bill_no}");
                }
                $lines[] = new JournalLineData(accountId: $apId, credit: $total, description: "AP {$bill->bill_no} - {$bill->vendor?->name}");

                $dto = new JournalData(journalDate: $bill->bill_date->format('Y-m-d'), type: 'PV', referenceNo: $bill->bill_no, narration: "Vendor Bill {$bill->bill_no}", lines: $lines, partnerId: $bill->vendor_id, partnerType: 'vendor', sourceType: 'purchase_bill', sourceId: $bill->id);
                $journalId = $posting->postJournal($dto)->id;
            }
            $bill->update(['status' => 'posted', 'journal_id' => $journalId, 'posted_by' => auth()->id(), 'posted_at' => now()]);

            return $bill->fresh(['items', 'journal']);
        });
    }

    public function void(VendorBill $bill): VendorBill
    {
        if (! $bill->isPosted()) {
            throw new \DomainException('Only posted bill can be voided.');
        }
        if ((float) $bill->paid_amount > 0.001) {
            throw new \DomainException('Paid bill cannot be voided — reverse payment first.');
        }

        return DB::transaction(function () use ($bill): VendorBill {
            if ($bill->journal_id && app()->bound(AccountsPostingProviderInterface::class)) {
                $posting = app(AccountsPostingProviderInterface::class);
                try {
                    $posting->voidJournal($bill->journal_id, "Void bill {$bill->bill_no}");
                } catch (\Throwable $e) {
                    // Do NOT swallow this. The catch used to discard the failure and the next line
                    // cancelled the bill anyway, leaving the AP/inventory journal posted against a
                    // cancelled document — and because it sits inside DB::transaction, swallowing
                    // also defeats the rollback. The controller catches DomainException and flashes
                    // it, so the bill stays posted and the user is told why.
                    throw new \DomainException(
                        "Could not reverse the journal for {$bill->bill_no}, so it was left posted: {$e->getMessage()}",
                        0,
                        $e
                    );
                }
            }
            $bill->update(['status' => 'cancelled']);

            return $bill->fresh();
        });
    }

    public function recordPayment(VendorBill $bill, array $data): PurchasePayment
    {
        if (! $bill->isPosted()) {
            throw new \DomainException('Only posted bills can receive payments.');
        }

        return DB::transaction(function () use ($bill, $data): PurchasePayment {
            $payNo = 'PP-'.str_pad((string) (PurchasePayment::max('id') + 1), 5, '0', STR_PAD_LEFT);
            $payment = PurchasePayment::create($data + ['payment_no' => $payNo, 'bill_id' => $bill->id, 'vendor_id' => $bill->vendor_id, 'created_by' => auth()->id()]);
            if (app()->bound(AccountsPostingProviderInterface::class)) {
                $posting = app(AccountsPostingProviderInterface::class);
                $purpose = "payment {$payment->payment_no} against {$bill->bill_no}";
                $apCode = config('accounts.defaults.payable_code', '2100');
                $cashCode = config('accounts.defaults.cash_code', '1001');
                $bank = ($data['bank_id'] ?? null) ? Bank::find($data['bank_id']) : null;

                // Both legs are required. On a miss this used to skip the journal while the
                // increment below still ran, so the bill reported paid_amount and payment_status
                // 'paid' for cash the ledger had never seen. Throwing rolls the payment back too —
                // it was created inside this same transaction.
                $cashId = $bank?->account_id ?? ChartOfAccounts::requireId($posting, $cashCode, $purpose);
                $apId = ChartOfAccounts::requireId($posting, $apCode, $purpose);

                $dto = new JournalData(journalDate: $data['payment_date'], type: 'PV', referenceNo: $payment->payment_no, narration: "Payment {$payment->payment_no} for {$bill->bill_no}", lines: [
                    new JournalLineData(accountId: $apId, debit: (float) $data['amount']),
                    new JournalLineData(accountId: $cashId, credit: (float) $data['amount']),
                ], partnerId: $bill->vendor_id, partnerType: 'vendor', sourceType: 'purchase_payment', sourceId: $payment->id);
                $payment->update(['journal_id' => $posting->postJournal($dto)->id]);
            }
            $bill->increment('paid_amount', (float) $data['amount']);
            $fresh = $bill->fresh();
            $fresh->update(['payment_status' => (float) $fresh->paid_amount >= (float) $fresh->total_amount ? 'paid' : 'partial']);

            return $payment;
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
