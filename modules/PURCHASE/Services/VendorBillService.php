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
use Modules\CORE\Services\ApprovalService;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Product;
use Modules\PURCHASE\Interfaces\VendorBillRepositoryInterface;
use Modules\PURCHASE\Models\PurchaseOrderItem;
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

        // A bill charges for goods: every line carries a positive quantity. A zero or negative
        // line would post a journal that still balances — the credit side simply shrinks — while
        // the billed-quantity guards below compare against received quantities that are never
        // negative. Credits travel as a purchase return, not as a negative bill line.
        $bill->loadMissing('items');
        if ($bill->items->isEmpty()) {
            throw new \DomainException("Bill {$bill->bill_no} has no lines — add what the vendor charged for before posting.");
        }
        foreach ($bill->items as $line) {
            if ((float) $line->qty <= 0) {
                throw new \DomainException("Bill {$bill->bill_no} line \"{$line->product_name}\" has quantity {$line->qty} — billed quantities must be positive.");
            }
        }

        // One invoice per delivery. A bill raised against a receipt already billed elsewhere would
        // reverse the same GRNI a second time: the accrual would go negative, AP would carry two
        // liabilities for one delivery, and both documents would look perfectly ordinary. The check
        // is by `receipt_id` and ignores cancelled bills, so voiding the wrong one re-opens the way.
        if ($bill->receipt_id) {
            $sibling = VendorBill::query()
                ->where('receipt_id', $bill->receipt_id)
                ->whereKeyNot($bill->id)
                ->whereNotIn('status', ['cancelled'])
                ->first();

            if ($sibling !== null) {
                throw new \DomainException(sprintf(
                    'GRN %s is already billed on %s. One delivery gets one vendor invoice — void that bill first if it was wrong.',
                    $bill->receipt?->receipt_no ?: ('#'.$bill->receipt_id),
                    $sibling->bill_no,
                ));
            }
        }

        // Generic approval matrix (Phase 1) — before the transaction so the
        // pending request survives a refusal. No-op without an active policy.
        app(ApprovalService::class)
            ->assertAllowed('vendor_bill', $bill->id, (float) $bill->total_amount, $bill->bill_no);

        return DB::transaction(function () use ($bill): VendorBill {
            $bill->load(['items.product', 'vendor']);

            // Refuse to bill more than arrived (or was ordered) before the journal exists. A bill
            // that charges for unreceived goods books a liability nobody owes and — on a GRN bill —
            // reverses an accrual for stock that never landed.
            $this->applyBillQuantities($bill);

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
                    $posting->voidJournal($bill->journal_id, "Void bill {$bill->bill_no}", true);
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
            // Give the billed quantities back: voiding re-opens the receipt (one bill per
            // receipt) and the order lines for a corrected bill.
            $this->reverseBillQuantities($bill);

            $bill->update(['status' => 'cancelled']);

            return $bill->fresh();
        });
    }

    public function recordPayment(VendorBill $bill, array $data): PurchasePayment
    {
        return DB::transaction(function () use ($bill, $data): PurchasePayment {
            // Check the latest balance under a row lock, not a stale form/model. This also
            // serializes concurrent payments before either can move cash or the ledger.
            $bill = VendorBill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
            if (! $bill->isPosted()) {
                throw new \DomainException('Only posted bills can receive payments.');
            }
            $amount = $data['amount'] ?? null;
            if (! is_numeric($amount) || (float) $amount <= 0 || round((float) $amount, 2) != (float) $amount) {
                throw new \DomainException('Payment must be a positive amount with at most two decimal places.');
            }
            $dueCents = (int) round((float) $bill->total_amount * 100) - (int) round((float) $bill->paid_amount * 100);
            // A debit note reduces what we owe without touching paid_amount — that column is cash
            // truth, what actually left — so the subledger is the final word on what is still due.
            // Without this a payment the bill header allows would settle against a smaller payable
            // and the surplus would vanish into FIFO.
            if ($bill->journal_id) {
                $payable = DB::table('acc_payables')->where('journal_id', $bill->journal_id)->lockForUpdate()->first();
                if ($payable) {
                    $subledgerCents = (int) round(((float) $payable->amount - (float) $payable->paid_amount) * 100);
                    $dueCents = min($dueCents, $subledgerCents);
                }
            }
            if ((int) round((float) $amount * 100) > $dueCents) {
                throw new \DomainException('Payment cannot exceed the outstanding bill balance.');
            }
            $method = $data['method'] ?? 'cash';
            if (! in_array($method, ['cash', 'bank', 'bkash', 'card'], true)) {
                throw new \DomainException('Invalid payment method.');
            }
            $bank = null;
            if ($method !== 'cash') {
                $bank = Bank::active()->find($data['bank_id'] ?? null);
                if (! $bank || ! $bank->account_id) {
                    throw new \DomainException('Choose an active bank or wallet linked to a ledger account for non-cash payment.');
                }
            } else {
                $data['bank_id'] = null;
            }
            $payNo = 'PP-'.str_pad((string) (PurchasePayment::max('id') + 1), 5, '0', STR_PAD_LEFT);
            $payment = PurchasePayment::create($data + ['payment_no' => $payNo, 'bill_id' => $bill->id, 'vendor_id' => $bill->vendor_id, 'created_by' => auth()->id()]);
            if (app()->bound(AccountsPostingProviderInterface::class)) {
                $posting = app(AccountsPostingProviderInterface::class);
                $purpose = "payment {$payment->payment_no} against {$bill->bill_no}";
                $apCode = config('accounts.defaults.payable_code', '2100');
                $cashCode = config('accounts.defaults.cash_code', '1001');

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

            return $payment->fresh();
        });
    }

    /**
     * Void a vendor payment: reverse its journal (which un-settles the linked payable), take the
     * cash back off the bill, and mark the payment void with an audit trail.
     *
     * A wrong payment previously had no way back. The payment row is kept, not deleted: the
     * voided row is the evidence that money left and was then returned, and deleting it would
     * renumber nothing but explain nothing either.
     */
    public function voidPayment(PurchasePayment $payment, string $reason): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $reason): PurchasePayment {
            $payment = PurchasePayment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if (($payment->status ?? 'posted') === 'void') {
                throw new \DomainException("{$payment->payment_no} is already voided.");
            }
            $bill = VendorBill::whereKey($payment->bill_id)->lockForUpdate()->firstOrFail();

            if ($payment->journal_id && app()->bound(AccountsPostingProviderInterface::class)) {
                try {
                    app(AccountsPostingProviderInterface::class)
                        ->voidJournal($payment->journal_id, "Void payment {$payment->payment_no}: {$reason}", true);
                } catch (\Throwable $e) {
                    // See void(): swallowing here would mark the payment void while its journal —
                    // and its payable settlement — stayed posted.
                    throw new \DomainException(
                        "Could not reverse the journal for {$payment->payment_no}, so it was left posted: {$e->getMessage()}",
                        0,
                        $e
                    );
                }
            }

            $bill->decrement('paid_amount', (float) $payment->amount);
            $fresh = $bill->fresh();
            $paidCents = (int) round((float) $fresh->paid_amount * 100);
            $totalCents = (int) round((float) $fresh->total_amount * 100);
            $fresh->update(['payment_status' => $paidCents >= $totalCents ? 'paid' : ($paidCents > 0 ? 'partial' : 'unpaid')]);

            $payment->update(['status' => 'void', 'voided_by' => auth()->id(), 'voided_at' => now(), 'void_reason' => $reason]);

            return $payment->fresh();
        });
    }

    /**
     * Validate the billed quantities against what arrived (or was ordered) and consume them.
     *
     * One bill per receipt stops the same delivery being billed twice, but it never stopped a
     * single bill charging for MORE than the delivery — or a bill with no receipt at all charging
     * a PO that was already fully billed elsewhere. `qty_billed` on the order lines is the running
     * total that makes the second case checkable; it is incremented here and given back on void.
     *
     * Service lines (freight, clearing, labour) carry no goods and are not guarded: a vendor can
     * legitimately bill handling that was never on the order.
     */
    private function applyBillQuantities(VendorBill $bill): void
    {
        $plan = $this->planBillQuantities($bill, true);
        if ($plan === []) {
            return;
        }
        $lines = PurchaseOrderItem::whereIn('id', array_keys($plan))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($plan as $id => $qty) {
            if ($qty > 0.0001 && $lines->has($id)) {
                $lines->get($id)->increment('qty_billed', $qty);
            }
        }
    }

    /**
     * Give billed quantities back on void. Lenient by design: a bill posted before the guards
     * existed may charge quantities the plan can no longer resolve, and that must never trap it
     * in posted state. Each order line only gives back what it holds — legacy lines that never
     * tracked `qty_billed` simply stay at zero.
     */
    private function reverseBillQuantities(VendorBill $bill): void
    {
        $plan = $this->planBillQuantities($bill, false);
        if ($plan === []) {
            return;
        }
        $lines = PurchaseOrderItem::whereIn('id', array_keys($plan))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($plan as $id => $qty) {
            if (! $lines->has($id)) {
                continue;
            }
            $take = min((float) $lines->get($id)->qty_billed, $qty);
            if ($take > 0.0001) {
                $lines->get($id)->decrement('qty_billed', $take);
            }
        }
    }

    /**
     * @return array<int, float> purchase order line id => billed qty to consume
     */
    private function planBillQuantities(VendorBill $bill, bool $strict): array
    {
        $bill->loadMissing(['items.product', 'receipt.items', 'order']);
        $lines = $bill->items->filter(fn ($l) => ($l->product?->type ?? 'stockable') !== 'service');
        if ($lines->isEmpty()) {
            return [];
        }

        if ($bill->receipt_id && $bill->receipt) {
            $receipt = $bill->receipt;
            if ($strict) {
                if ((int) $bill->vendor_id !== (int) $receipt->vendor_id) {
                    throw new \DomainException("Bill {$bill->bill_no} names a different vendor than GRN {$receipt->receipt_no} — a bill charges the vendor who delivered.");
                }
                if ((int) $bill->warehouse_id !== (int) $receipt->warehouse_id) {
                    throw new \DomainException("Bill {$bill->bill_no} names a different warehouse than GRN {$receipt->receipt_no} — a bill follows its delivery.");
                }
            }
            $receiptLines = $receipt->items->keyBy('id');
            $room = [];
            foreach ($receiptLines as $id => $rl) {
                $room[$id] = (float) $rl->qty;
            }
            // The receipt's room is per bill, but the PO's unbilled room is shared across bills:
            // without intersecting the two, a second bill against the same receipt would pass with
            // the full received quantity again and double-bill the delivery. Locked, so two bills
            // posting together cannot both spend the same unbilled units.
            $poRoom = [];
            if ($strict) {
                $oids = $receiptLines->pluck('order_item_id')->filter()->unique()->values()->all();
                if ($oids !== []) {
                    foreach (PurchaseOrderItem::whereIn('id', $oids)->orderBy('id')->lockForUpdate()->get() as $pl) {
                        $poRoom[$pl->id] = (float) $pl->qty - (float) $pl->qty_billed;
                    }
                }
            }
            $plan = [];
            foreach ($lines as $line) {
                $need = (float) $line->qty;
                if ($line->receipt_item_id) {
                    $rl = $receiptLines->get($line->receipt_item_id);
                    if ($rl === null) {
                        if (! $strict) {
                            continue;
                        }
                        throw new \DomainException("Bill {$bill->bill_no} line \"{$line->product_name}\" references a receipt line that is not on GRN {$receipt->receipt_no}.");
                    }
                    if ((int) $rl->product_id !== (int) $line->product_id || ($rl->variant_id ?? null) != ($line->variant_id ?? null)) {
                        if (! $strict) {
                            continue;
                        }
                        throw new \DomainException("Bill {$bill->bill_no} line \"{$line->product_name}\" is linked to a different product on GRN {$receipt->receipt_no}.");
                    }
                    $candidates = [$rl];
                } else {
                    $candidates = $receiptLines
                        ->filter(fn ($rl) => (int) $rl->product_id === (int) $line->product_id && ($rl->variant_id ?? null) == ($line->variant_id ?? null))
                        ->sortKeys()->all();
                }
                foreach ($candidates as $rl) {
                    if ($need <= 0.0001) {
                        break;
                    }
                    $take = min($room[$rl->id] ?? 0, $need);
                    if ($strict && $rl->order_item_id && isset($poRoom[$rl->order_item_id])) {
                        $take = min($take, max(0.0, $poRoom[$rl->order_item_id]));
                    }
                    if ($take <= 0) {
                        continue;
                    }
                    $room[$rl->id] -= $take;
                    $need -= $take;
                    if ($rl->order_item_id) {
                        if (isset($poRoom[$rl->order_item_id])) {
                            $poRoom[$rl->order_item_id] -= $take;
                        }
                        $plan[$rl->order_item_id] = ($plan[$rl->order_item_id] ?? 0) + $take;
                    }
                }
                if ($strict && $need > 0.0001) {
                    $received = (float) collect($candidates)->sum('qty');
                    // Which wall did the bill hit: the receipt's, or units already on another bill?
                    // Quoting "received" when the goods arrived but are billed would send the clerk
                    // to receive what is already on the shelf.
                    $rcptLeft = 0.0;
                    $poLeft = 0.0;
                    $seenOid = [];
                    foreach ($candidates as $rl) {
                        $rcptLeft += $room[$rl->id] ?? 0;
                        if ($rl->order_item_id && ! isset($seenOid[$rl->order_item_id]) && isset($poRoom[$rl->order_item_id])) {
                            $seenOid[$rl->order_item_id] = true;
                            // Plus what this bill itself just took: the refusal rolls everything
                            // back, so the clerk is told what stays unbilled after it fails.
                            $poLeft += $poRoom[$rl->order_item_id] + ($plan[$rl->order_item_id] ?? 0);
                        }
                    }
                    if ($seenOid !== [] && $rcptLeft >= $need - 0.0001) {
                        $poNo = $bill->order?->order_no ?? $receipt->order?->order_no ?? 'order';
                        throw new \DomainException(sprintf(
                            'Bill %s charges %.2f of "%s" but PO %s only has %.2f unbilled. The rest is already on another bill.',
                            $bill->bill_no, (float) $line->qty, $line->product_name, $poNo, max(0.0, $poLeft)
                        ));
                    }
                    throw new \DomainException(sprintf(
                        'Bill %s charges %.2f of "%s" but GRN %s only received %.2f. Bill what arrived — extra units need their own receipt.',
                        $bill->bill_no, (float) $line->qty, $line->product_name, $receipt->receipt_no, $received
                    ));
                }
            }

            return $plan;
        }

        if ($bill->order_id && $bill->order) {
            $order = $bill->order;
            if ($strict) {
                if ((int) $bill->vendor_id !== (int) $order->vendor_id) {
                    throw new \DomainException("Bill {$bill->bill_no} names a different vendor than PO {$order->order_no} — a bill charges the vendor who was ordered from.");
                }
                if ((int) $bill->warehouse_id !== (int) $order->warehouse_id) {
                    throw new \DomainException("Bill {$bill->bill_no} names a different warehouse than PO {$order->order_no} — a bill follows its order.");
                }
            }
            $poLines = PurchaseOrderItem::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $room = [];
            foreach ($poLines as $pl) {
                // Posting consumes what is still unbilled; voiding gives back what is billed.
                $room[$pl->id] = $strict ? (float) $pl->qty - (float) $pl->qty_billed : (float) $pl->qty_billed;
            }
            $plan = [];
            foreach ($lines as $line) {
                $need = (float) $line->qty;
                foreach ($poLines as $pl) {
                    if ($need <= 0.0001) {
                        break;
                    }
                    if ((int) $pl->product_id !== (int) $line->product_id || ($pl->variant_id ?? null) != ($line->variant_id ?? null)) {
                        continue;
                    }
                    $take = min($room[$pl->id] ?? 0, $need);
                    if ($take <= 0) {
                        continue;
                    }
                    $room[$pl->id] -= $take;
                    $need -= $take;
                    $plan[$pl->id] = ($plan[$pl->id] ?? 0) + $take;
                }
                if ($strict && $need > 0.0001) {
                    $unbilled = 0.0;
                    foreach ($poLines as $pl) {
                        if ((int) $pl->product_id === (int) $line->product_id && ($pl->variant_id ?? null) == ($line->variant_id ?? null)) {
                            $unbilled += (float) $pl->qty - (float) $pl->qty_billed;
                        }
                    }
                    throw new \DomainException(sprintf(
                        'Bill %s charges %.2f of "%s" but PO %s only has %.2f unbilled. The rest is already on another bill.',
                        $bill->bill_no, (float) $line->qty, $line->product_name, $order->order_no, max(0.0, $unbilled)
                    ));
                }
            }

            return $plan;
        }

        // A bill with neither receipt nor order (services, utilities, ad-hoc purchases) has no
        // source quantities to guard against.
        return [];
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
