<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Support\ChartOfAccounts;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Models\FifoLayer;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\PURCHASE\Models\LandedCost;

class LandedCostService
{
    public function nextNo(): string
    {
        $last = LandedCost::withTrashed()->orderByDesc('id')->value('lc_no');
        $n = $last ? (int) preg_replace('/\D/', '', $last) : 0;

        return 'LC-'.str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }

    public function create(array $data): LandedCost
    {
        return DB::transaction(function () use ($data): LandedCost {
            $data['lc_no'] = $this->nextNo();
            $data['status'] = 'draft';
            $data['created_by'] = auth()->id();
            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $data['total_cost'] = collect($lines)->sum(fn ($l) => (float) ($l['amount'] ?? 0));
            $lc = LandedCost::create($data);
            foreach ($lines as $l) {
                $lc->lines()->create($l);
            }

            return $lc->fresh(['lines', 'receipt.items.product', 'allocations']);
        });
    }

    public function allocate(LandedCost $lc): LandedCost
    {
        if (! $lc->isDraft()) {
            throw new \DomainException('Only draft can be allocated');
        }

        return DB::transaction(function () use ($lc): LandedCost {
            $lc->load(['receipt.items.product', 'lines']);
            if ($lc->lines->isEmpty()) {
                throw new \DomainException('Add at least one cost line before allocating');
            }
            $receipt = $lc->receipt;
            if (! $receipt) {
                throw new \DomainException('GRN not found for allocation');
            }
            $totalLanded = (float) $lc->total_cost;
            $items = $receipt->items;
            if ($items->isEmpty()) {
                throw new \DomainException('GRN has no items to allocate');
            }
            $basis = $lc->allocation_basis ?? 'value';
            // compute allocation weight per item
            $weights = [];
            $totalWeight = 0;
            foreach ($items as $it) {
                $w = match ($basis) {
                    'qty' => (float) $it->qty,
                    'weight' => (float) ($it->product->weight ?? 1) * (float) $it->qty,
                    default => (float) $it->line_total, // value
                };
                $weights[$it->id] = $w;
                $totalWeight += $w;
            }
            if ($totalWeight <= 0) {
                $totalWeight = 1;
            }
            $lc->allocations()->delete();
            $allocations = [];
            $cumulative = 0;
            foreach ($items as $idx => $it) {
                $share = $weights[$it->id] / $totalWeight;
                // last item gets remainder to handle rounding
                $isLast = $idx === $items->count() - 1;
                $allocated = $isLast ? round($totalLanded - $cumulative, 2) : round($totalLanded * $share, 2);
                $cumulative += $allocated;
                $landedUnit = $it->qty > 0 ? $allocated / (float) $it->qty : 0;
                $newUnit = (float) $it->unit_price + $landedUnit;
                $alloc = $lc->allocations()->create([
                    'receipt_item_id' => $it->id,
                    'product_id' => $it->product_id,
                    'qty' => $it->qty,
                    'base_value' => $it->line_total,
                    'allocated_cost' => $allocated,
                    'landed_unit_cost' => $landedUnit,
                    'new_unit_cost' => $newUnit,
                ]);
                $allocations[] = $alloc;
            }
            // Apply to inventory: adjust unit_cost of FIFO layer / stock
            if (app()->bound(InventoryStockProviderInterface::class)) {
                // For each allocation, we need to adjust the FIFO layer's unit_cost and stock avg_cost
                // Approach: create adjustment stock move with difference? Simpler: directly update fifo_layer unit_cost and stock
                foreach ($allocations as $alloc) {
                    $product = Product::find($alloc->product_id);
                    $stock = Stock::where('product_id', $alloc->product_id)
                        ->where('warehouse_id', $receipt->warehouse_id)->first();
                    // Find FIFO layer for this GRN's receipt (latest layer for this product/wh created by this receipt)
                    $layer = FifoLayer::where('product_id', $alloc->product_id)
                        ->where('warehouse_id', $receipt->warehouse_id)
                        ->where('stock_move_id', function ($q) use ($receipt) {
                            $q->select('id')->from('inventory_stock_moves')->where('reference_type', 'purchase_receipt')->where('reference_no', $receipt->receipt_no)->limit(1);
                        })->latest()->first();
                    // fallback: last layer for product/wh
                    if (! $layer) {
                        $layer = FifoLayer::where('product_id', $alloc->product_id)
                            ->where('warehouse_id', $receipt->warehouse_id)->where('qty_remaining', '>', 0)->latest('id')->first();
                    }
                    if ($layer) {
                        // bump layer cost
                        $oldTotal = (float) $layer->qty_remaining * (float) $layer->unit_cost;
                        $newTotal = $oldTotal + (float) $alloc->allocated_cost * ((float) $layer->qty_remaining / (float) $alloc->qty);
                        // proportional if partial already consumed: distribute proportionally to remaining qty
                        // Actually simpler: increase unit_cost by landed_unit_cost, but only for remaining qty
                        $layer->increment('unit_cost', (float) $alloc->landed_unit_cost);
                        $layer->increment('total_cost', (float) $alloc->allocated_cost * ((float) $layer->qty_remaining / (float) $alloc->qty));
                    }
                    if ($stock) {
                        // increase stock total_value by allocated cost proportional to remaining / original qty
                        // If stock qty == receipt qty (fresh GRN), just add full allocated
                        // Otherwise proportional to (remaining / original)
                        $remainingRatio = $stock->qty_on_hand > 0 ? min(1, (float) $stock->qty_on_hand / (float) $alloc->qty) : 0;
                        // For simplicity, if allocation is recent and stock originated from this GRN, full amount; else proportional
                        $addValue = (float) $alloc->allocated_cost * max(0.1, $remainingRatio);
                        // Ensure we don't over-add if stock has been partially consumed, use remainingRatio
                        $stock->increment('total_value', $addValue);
                        $stock->avg_cost = $stock->qty_on_hand > 0 ? (float) $stock->total_value / (float) $stock->qty_on_hand : $stock->avg_cost;
                        $stock->save();
                    }
                }
            }
            // Journal for landed cost: Dr Inventory, Cr Expense/Payable accounts
            if (app()->bound(AccountsPostingProviderInterface::class) && $totalLanded > 0) {
                $posting = app(AccountsPostingProviderInterface::class);
                $purpose = "landed cost {$lc->lc_no} for GRN {$receipt->receipt_no}";
                $invCode = config('accounts.defaults.inventory_code', '1200');
                $landedCode = config('accounts.defaults.landed_cost_code', '6000');
                // Stock value was already incremented above, so the journal is not optional.
                $invId = ChartOfAccounts::requireId($posting, $invCode, $purpose);
                $lines = [];
                $lines[] = new JournalLineData(accountId: $invId, debit: $totalLanded, description: "Landed cost {$lc->lc_no} → Inventory ({$receipt->receipt_no})");
                foreach ($lc->lines as $cl) {
                    // The old chain ended in `?? $invId`, which credited Inventory for the cost it
                    // had just debited — the journal netted to zero, capitalised nothing, and the
                    // GL disagreed with the stock valuation updated in this same transaction.
                    // The credit leg used to fall back to a hardcoded '6000' with a comment saying
                    // no config key existed. `accounts.defaults.landed_cost_code` is that key, and a
                    // cost line that names its own account still wins.
                    $acctId = $cl->account_id ?? ChartOfAccounts::requireId($posting, $landedCode, $purpose);
                    $lines[] = new JournalLineData(accountId: $acctId, credit: (float) $cl->amount, description: "{$cl->name} {$lc->lc_no}");
                }
                $dto = new JournalData(journalDate: now()->toDateString(), type: 'JV', referenceNo: $lc->lc_no, narration: "Landed cost allocation {$lc->lc_no} for GRN {$receipt->receipt_no}", lines: $lines, sourceType: 'landed_cost', sourceId: $lc->id);
                $posting->postJournal($dto);
            }
            $lc->update(['status' => 'allocated', 'posted_by' => auth()->id(), 'posted_at' => now()]);

            return $lc->fresh(['lines', 'allocations.product', 'receipt']);
        });
    }

    public function cancel(LandedCost $lc): LandedCost
    {
        if ($lc->status === 'allocated') {
            throw new \DomainException('Allocated landed cost cannot be cancelled — reverse via journal');
        }
        $lc->update(['status' => 'cancelled']);

        return $lc;
    }
}
