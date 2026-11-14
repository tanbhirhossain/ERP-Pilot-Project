<?php

namespace Modules\SALES\Services;

use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

/**
 * Credit control shared by every credit-sale path (POS, Sales Order, Invoice).
 *
 * Previously only the POS terminal enforced `credit_limit`; a customer could be
 * refused at the counter and then sold the same goods on credit through
 * SO→INV, which never looked at the limit. This service is the single rule, so
 * all three doors apply the same exposure arithmetic.
 *
 * Exposure = open receivables ledger + dues rung up in drawers not yet posted
 *            + the new credit amount being attempted.
 *
 * `credit_limit <= 0` means "no limit set", not "a limit of zero" — matching the
 * POS behaviour and the seeded walk-in customers.
 */
class CreditControlService
{
    /**
     * Current credit exposure for a customer in BDT: open receivables plus dues
     * rung up in drawers not yet posted. Excludes any new sale. Used by the
     * scheduled credit-limit review (Phase 2 — F21) and reusable for UI badges.
     */
    public function currentExposure(int $customerId): float
    {
        $owed = (float) Receivable::open()->where('partner_id', $customerId)->sum('balance');

        $openSessions = PosSession::where('status', 'open')->pluck('id');
        $unposted = (float) PosSale::query()
            ->where('customer_id', $customerId)
            ->where('status', '<>', 'void')
            ->whereRaw('due_amount > collected_amount')
            ->where(fn ($q) => $q->whereNull('session_id')->orWhereIn('session_id', $openSessions))
            ->sum(DB::raw('due_amount - collected_amount'));

        return $owed + $unposted;
    }

    /**
     * @throws \DomainException when the new credit would breach the limit.
     */
    public function assertWithinLimit(?int $customerId, float $newCreditAmount, string $context = 'sale'): void
    {
        if ($newCreditAmount <= 0 || $customerId === null) {
            return;
        }

        $partner = Partner::find($customerId);

        if ($partner === null) {
            return;
        }

        $limitCents = (int) round(((float) $partner->credit_limit) * 100);

        if ($limitCents <= 0) {
            return; // no limit configured
        }

        $owedCents = (int) round(((float) Receivable::open()->where('partner_id', $customerId)->sum('balance')) * 100);

        $openSessions = PosSession::where('status', 'open')->pluck('id');
        $unpostedCents = (int) round(((float) PosSale::query()
            ->where('customer_id', $customerId)
            ->where('status', '<>', 'void')
            ->whereRaw('due_amount > collected_amount')
            ->where(fn ($q) => $q->whereNull('session_id')->orWhereIn('session_id', $openSessions))
            ->sum(DB::raw('due_amount - collected_amount'))) * 100);

        $newCents = (int) round($newCreditAmount * 100);
        $exposureCents = $owedCents + $unpostedCents + $newCents;

        if ($exposureCents > $limitCents) {
            throw new \DomainException(sprintf(
                'This %s would take %s past their credit limit of %s BDT. They already owe %s BDT%s, '
                .'and this adds %s BDT, which is %s BDT over. Take a payment first, raise the limit on '
                .'the customer record, or record the sale as paid.',
                $context,
                $partner->name,
                number_format($limitCents / 100, 2),
                number_format(($owedCents + $unpostedCents) / 100, 2),
                $unpostedCents > 0 ? ' (including '.number_format($unpostedCents / 100, 2).' BDT not yet posted from open drawers)' : '',
                number_format($newCents / 100, 2),
                number_format(($exposureCents - $limitCents) / 100, 2),
            ));
        }
    }
}
