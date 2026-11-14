<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Support\Carbon;
use Modules\ACCOUNTS\Models\Withholding;
use Modules\CORE\Models\CoreSetting;
use Modules\PURCHASE\Models\PurchaseReturn;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\SalesReturn;

/**
 * F17 — Mushak 9.1 (Bangladesh monthly VAT return).
 *
 * The return resolves output VAT on supplies against the input VAT credit on
 * purchases, then nets off VAT deducted at source (VDS) that customers withheld
 * and deposited on the business's behalf. Exactly one of "net payable" and
 * "credit carried forward" is positive.
 *
 * Source registers (all period-scoped, live documents only):
 *   Output  = posted sales invoices + completed POS sales, bucketed by rate.
 *             POS is a separate supply channel — it does NOT raise invoices.
 *   Decreasing output adjustment = approved sales returns (credit notes).
 *   Input   = posted vendor bills.
 *   Decreasing input adjustment  = posted purchase returns (debit notes).
 *   VDS credit = VDS withholdings *suffered on our sales* in the period.
 *
 * Money is summed in integer cents to avoid binary-float drift, then returned
 * as 2-decimal floats for display (a report, not a persisted money column).
 */
class VatReturnService
{
    /** Statuses that make a sales invoice a live supply for VAT. */
    private const INVOICE_LIVE = ['posted', 'paid', 'partially_paid', 'overdue'];

    /** Statuses that make a sales return an approved credit note. */
    private const RETURN_LIVE = ['approved', 'restocked', 'refunded'];

    /**
     * Build the full Mushak 9.1 working paper for a calendar month.
     *
     * @return array<string, mixed>
     */
    public function forMonth(string $month): array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $from = $start->toDateString();
        $to = $end->toDateString();

        // ── Output tax, bucketed by rate (Part 2 / 3 / 4) ──────────────────
        $standard = ['base' => 0, 'vat' => 0];
        $reduced = ['base' => 0, 'vat' => 0];
        $zero = ['base' => 0, 'vat' => 0];

        // Invoice lines carry an explicit rate; bucket each line.
        $invoiceLines = Invoice::query()
            ->whereIn('status', self::INVOICE_LIVE)
            ->whereBetween('invoice_date', [$from, $to])
            ->with(['items:id,invoice_id,line_total,vat_amount,vat_rate'])
            ->get(['id']);
        foreach ($invoiceLines as $invoice) {
            foreach ($invoice->items as $item) {
                $rate = (float) $item->vat_rate;
                $bucket = $rate <= 0 ? 'zero' : ($rate >= 15 ? 'standard' : 'reduced');
                ${$bucket}['base'] += $this->cents($item->line_total);
                if ($bucket !== 'zero') {
                    ${$bucket}['vat'] += $this->cents($item->vat_amount);
                }
            }
        }

        // POS sales have no per-line rate column; retail POS is standard-rated.
        $posBase = $this->cents(PosSale::query()
            ->where('status', 'completed')
            ->whereBetween('sale_date', [$from, $to])
            ->sum('subtotal'));
        $posVat = $this->cents(PosSale::query()
            ->where('status', 'completed')
            ->whereBetween('sale_date', [$from, $to])
            ->sum('vat_amount'));
        $standard['base'] += $posBase;
        $standard['vat'] += $posVat;

        $outputVat = $standard['vat'] + $reduced['vat'];
        $outputBase = $standard['base'] + $reduced['base'] + $zero['base'];

        // ── Decreasing output adjustment: approved sales returns ──────────
        $salesReturnVat = $this->cents(SalesReturn::query()
            ->whereIn('status', self::RETURN_LIVE)
            ->whereBetween('return_date', [$from, $to])
            ->sum('vat_amount'));

        // ── Input tax credit (Part 6) ─────────────────────────────────────
        $inputBase = $this->cents(VendorBill::query()
            ->where('status', 'posted')
            ->whereBetween('bill_date', [$from, $to])
            ->sum('subtotal'));
        $inputVat = $this->cents(VendorBill::query()
            ->where('status', 'posted')
            ->whereBetween('bill_date', [$from, $to])
            ->sum('vat_amount'));

        // ── Decreasing input adjustment: posted purchase returns ──────────
        $purchaseReturnVat = $this->cents(PurchaseReturn::query()
            ->where('status', 'posted')
            ->whereBetween('return_date', [$from, $to])
            ->sum('vat_amount'));

        // ── VDS suffered on our sales — claimable only once certified ─────
        // A deduction we believe happened but hold no Mushak 6.6 certificate for
        // is not a deduction we can enter on the return.
        $vdsCredit = $this->cents(Withholding::query()
            ->where('type', 'vds')
            ->where('direction', 'outward')
            ->where('status', 'certified')
            ->whereBetween('period', [$from, $to])
            ->sum('amount'));

        $netOutput = $outputVat - $salesReturnVat;
        $netInput = $inputVat - $purchaseReturnVat;
        $netPosition = $netOutput - $netInput - $vdsCredit;

        return [
            'period' => ['from' => $from, 'to' => $to, 'label' => $start->format('F Y'), 'month' => $month],
            'company' => $this->company(),
            'output' => [
                'standard' => ['base' => $this->money($standard['base']), 'vat' => $this->money($standard['vat'])],
                'reduced' => ['base' => $this->money($reduced['base']), 'vat' => $this->money($reduced['vat'])],
                'zero' => ['base' => $this->money($zero['base'])],
                'total_base' => $this->money($outputBase),
                'total_vat' => $this->money($outputVat),
            ],
            'output_adjustment' => $this->money($salesReturnVat),
            'net_output_vat' => $this->money($netOutput),
            'input' => [
                'total_base' => $this->money($inputBase),
                'total_vat' => $this->money($inputVat),
            ],
            'input_adjustment' => $this->money($purchaseReturnVat),
            'net_input_vat' => $this->money($netInput),
            'vds_credit' => $this->money($vdsCredit),
            'net_payable' => $this->money(max($netPosition, 0)),
            'credit_carried_forward' => $this->money(max(-$netPosition, 0)),
            'registers' => [
                'sales' => $this->salesRegister($from, $to),
                'purchases' => $this->purchaseRegister($from, $to),
            ],
        ];
    }

    /**
     * Sales register (Mushak 6.2 equivalent) — one row per live supply.
     *
     * @return list<array<string, mixed>>
     */
    private function salesRegister(string $from, string $to): array
    {
        $invoices = Invoice::query()
            ->whereIn('status', self::INVOICE_LIVE)
            ->whereBetween('invoice_date', [$from, $to])
            ->with('customer:id,name')
            ->orderBy('invoice_date')
            ->get(['id', 'invoice_no', 'invoice_date', 'customer_id', 'subtotal', 'vat_amount', 'total_amount'])
            ->map(fn (Invoice $i): array => [
                'date' => $i->invoice_date?->toDateString(),
                'ref' => $i->invoice_no,
                'party' => $i->customer?->name ?? '—',
                'channel' => 'Invoice',
                'base' => (float) $i->subtotal,
                'vat' => (float) $i->vat_amount,
                'total' => (float) $i->total_amount,
            ]);

        $pos = PosSale::query()
            ->where('status', 'completed')
            ->whereBetween('sale_date', [$from, $to])
            ->orderBy('sale_date')
            ->get(['id', 'pos_no', 'sale_date', 'customer_name', 'subtotal', 'vat_amount', 'total_amount'])
            ->map(fn (PosSale $s): array => [
                'date' => $s->sale_date?->toDateString(),
                'ref' => $s->pos_no,
                'party' => $s->customer_name ?: 'Walk-in',
                'channel' => 'POS',
                'base' => (float) $s->subtotal,
                'vat' => (float) $s->vat_amount,
                'total' => (float) $s->total_amount,
            ]);

        return $invoices->concat($pos)->sortBy('date')->values()->all();
    }

    /**
     * Purchase register (Mushak 6.1 equivalent) — one row per posted bill.
     *
     * @return list<array<string, mixed>>
     */
    private function purchaseRegister(string $from, string $to): array
    {
        return VendorBill::query()
            ->where('status', 'posted')
            ->whereBetween('bill_date', [$from, $to])
            ->with('vendor:id,name')
            ->orderBy('bill_date')
            ->get(['id', 'bill_no', 'bill_date', 'vendor_id', 'subtotal', 'vat_amount', 'total_amount'])
            ->map(fn (VendorBill $b): array => [
                'date' => $b->bill_date?->toDateString(),
                'ref' => $b->bill_no,
                'party' => $b->vendor?->name ?? '—',
                'base' => (float) $b->subtotal,
                'vat' => (float) $b->vat_amount,
                'total' => (float) $b->total_amount,
            ])->all();
    }

    /** @return array{name: string, address: string, bin: string} */
    private function company(): array
    {
        $stored = CoreSetting::whereIn('key', ['company.name', 'company.address', 'company.bin'])
            ->pluck('value', 'key');

        return [
            'name' => $stored['company.name'] ?? 'ERP Pilot Ltd.',
            'address' => $stored['company.address'] ?? 'Chattogram EPZ, Bangladesh',
            'bin' => $stored['company.bin'] ?? '001234567-0101',
        ];
    }

    /** Decimal-string|float|int → integer cents. */
    private function cents(string|float|int|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** Integer cents → 2-decimal float for display. */
    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
