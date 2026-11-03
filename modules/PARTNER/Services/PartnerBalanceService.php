<?php

namespace Modules\PARTNER\Services;

use Illuminate\Support\Facades\DB;
use Modules\PARTNER\Models\Partner;

/**
 * Recalculates cached current_balance from ledger.
 * Phase 1: from opening_balance only (no ACCOUNTS yet).
 * Phase 3: will sum voucher_lines where partner_id = ?.
 */
class PartnerBalanceService
{
    public function recalculate(Partner $partner): Partner
    {
        // Phase 1: balance = opening_balance (no transactions yet)
        // Future: $balance = DB::table('accounts_voucher_lines')->where('partner_id', $partner->id)->sum(DB::raw('debit - credit'));
        $partner->update(['current_balance' => $partner->opening_balance]);
        return $partner->fresh();
    }

    public function recalculateAll(): int
    {
        $count = 0;
        Partner::query()->lazyById(200, 'id')->each(function (Partner $p) use (&$count): void {
            $this->recalculate($p);
            $count++;
        });
        return $count;
    }

    public function adjustBalance(Partner $partner, float $amount, string $description = ''): Partner
    {
        return DB::transaction(function () use ($partner, $amount, $description): Partner {
            $partner->increment('current_balance', $amount);
            $partner->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'balance_adjusted',
                'description' => $description ?: "Balance adjusted by {$amount}",
                'properties' => ['amount' => $amount, 'new_balance' => (float) $partner->fresh()->current_balance],
            ]);
            return $partner->fresh();
        });
    }
}
