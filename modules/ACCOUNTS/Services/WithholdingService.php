<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\TaxDeposit;
use Modules\ACCOUNTS\Models\Withholding;
use Modules\ACCOUNTS\Models\WithholdingRule;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;

/**
 * F17 — TDS/VDS withholding engine.
 *
 * Inward: when we pay a vendor for a notified service, we deduct TDS (income tax)
 * and/or VDS (VAT) at source and must deposit it to NBR. The deduction is computed
 * on the bill's taxable value (subtotal) at the configured rate, provided the base
 * meets the rule's threshold.
 *
 * Outward: a withholding customer deducts VDS on our sale and issues a Mushak 6.6
 * certificate; we record it so it credits our Mushak 9.1 once certified.
 *
 * Amounts are computed in integer cents to avoid float drift.
 */
class WithholdingService
{
    /**
     * Compute and record an inward deduction on a posted vendor bill.
     * Idempotent per (bill, rule): re-applying the same rule returns the existing row.
     */
    public function applyToBill(VendorBill $bill, WithholdingRule $rule): ?Withholding
    {
        abort_unless($bill->isPosted(), 422, 'Withholding applies only to posted bills.');

        $baseCents = $this->cents($bill->subtotal);
        if ($baseCents < $this->cents($rule->threshold)) {
            return null; // below threshold — no deduction required
        }

        $existing = Withholding::where('bill_id', $bill->id)
            ->where('rule_id', $rule->id)
            ->where('direction', 'inward')
            ->first();
        if ($existing) {
            return $existing;
        }

        $amountCents = (int) round($baseCents * ((float) $rule->rate) / 100);

        return Withholding::create([
            'type' => $rule->type,
            'direction' => 'inward',
            'rule_id' => $rule->id,
            'partner_id' => $bill->vendor_id,
            'bill_id' => $bill->id,
            'period' => $bill->bill_date?->toDateString() ?? now()->toDateString(),
            'base_amount' => $this->money($baseCents),
            'rate' => $rule->rate,
            'amount' => $this->money($amountCents),
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Record VDS a customer withheld on our sale (outward). Starts `pending`;
     * becomes `certified` (and claimable in Mushak 9.1) when the certificate is entered.
     */
    public function recordOutward(Invoice $invoice, float $rate, float $base, ?string $certificateNo = null): Withholding
    {
        $amountCents = (int) round($this->cents($base) * $rate / 100);

        return Withholding::create([
            'type' => 'vds',
            'direction' => 'outward',
            'partner_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'period' => $invoice->invoice_date?->toDateString() ?? now()->toDateString(),
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $this->money($amountCents),
            'status' => $certificateNo ? 'certified' : 'pending',
            'certificate_no' => $certificateNo,
            'created_by' => auth()->id(),
        ]);
    }

    /** Mark an outward VDS as certified once the Mushak 6.6 certificate is received. */
    public function certify(Withholding $withholding, string $certificateNo): Withholding
    {
        abort_unless($withholding->direction === 'outward', 422, 'Only outward VDS can be certified.');
        $withholding->update(['status' => 'certified', 'certificate_no' => $certificateNo]);

        return $withholding;
    }

    /**
     * Deposit a set of pending inward withholdings to NBR via a treasury challan.
     * All-or-nothing: the deposit and the status flips share one transaction.
     *
     * @param  list<int>  $withholdingIds
     * @param  array<string, mixed>  $data
     */
    public function deposit(array $withholdingIds, array $data): TaxDeposit
    {
        $rows = Withholding::whereIn('id', $withholdingIds)->where('direction', 'inward')->pending()->get();
        abort_if($rows->isEmpty(), 422, 'Select at least one pending inward withholding to deposit.');
        abort_if($rows->pluck('type')->unique()->count() > 1, 422, 'A single challan deposits one tax type (TDS or VDS), not both.');

        $totalCents = $rows->sum(fn (Withholding $w): int => $this->cents($w->amount));

        return DB::transaction(function () use ($rows, $data, $totalCents): TaxDeposit {
            $deposit = TaxDeposit::create([
                'deposit_no' => $data['deposit_no'] ?? $this->nextDepositNo(),
                'type' => $rows->first()->type,
                'period' => $data['period'],
                'amount' => $this->money($totalCents),
                'deposit_date' => $data['deposit_date'],
                'bank_id' => $data['bank_id'] ?? null,
                'challan_no' => $data['challan_no'] ?? null,
                'treasury_code' => $data['treasury_code'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($rows as $w) {
                $w->update(['status' => 'deposited', 'deposit_id' => $deposit->id, 'deposited_at' => now()]);
            }

            return $deposit;
        });
    }

    /** Pending inward liability, split by tax type — what we still owe NBR. */
    public function pendingSummary(): array
    {
        $rows = Withholding::inward()->pending()->get();

        return [
            'tds' => $this->money($rows->where('type', 'tds')->sum(fn ($w) => $this->cents($w->amount))),
            'vds' => $this->money($rows->where('type', 'vds')->sum(fn ($w) => $this->cents($w->amount))),
            'count' => $rows->count(),
        ];
    }

    private function nextDepositNo(): string
    {
        $seq = TaxDeposit::whereYear('created_at', now()->year)->count() + 1;

        return sprintf('TDEP-%s-%04d', now()->format('Y'), $seq);
    }

    private function cents(string|float|int|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
