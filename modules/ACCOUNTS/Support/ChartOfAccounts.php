<?php

namespace Modules\ACCOUNTS\Support;

use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;

/**
 * The single choke point for resolving a chart-of-accounts code before a document is posted.
 *
 * `AccountsPostingProviderInterface::accountIdByCode()` is declared `: ?int`, and that nullable
 * return is correct — a lookup should be able to miss. The defect was what the callers did with
 * a miss. Every posting path in PURCHASE, SALES and INVENTORY was shaped like this:
 *
 *     $apId = $posting->accountIdByCode($apCode);
 *     if ($apId) { ...build the lines...; $journalId = $posting->postJournal($dto)->id; }
 *     $document->update(['status' => 'posted', 'journal_id' => $journalId]);
 *
 * The status update sat *outside* the guard, so null meant "write no ledger entries and carry on
 * anyway". The document was marked posted with `journal_id = null`: payables never moved,
 * receivables never moved, a bill could be recorded as PAID with no cash entry anywhere, and a
 * trial balance that disagreed with every subledger produced no error and no log line.
 *
 * A stock install hits this immediately. The chart is seeded by `AccountsDemoSeeder`, and no migration provisions any account: report item 38
 * moved the last one — PURCHASE's GRNI 2115, written by `2026_11_03_000075_add_grni_to_receipts` — into that seeder, because content a
 * migration creates is invisible to the seeders that own it and can switch their guards off. That is exactly how this ledger went empty.
 * So after a plain `php artisan migrate` the chart holds no accounts at all, and every posting path below takes its null branch.
 *
 * These helpers convert a miss into a `DomainException` naming the code. Every caller posts inside
 * `DB::transaction()`, so the throw rolls the document back to draft, and the controllers — which
 * already catch `DomainException` and flash it — tell the operator which account to create.
 *
 * Not on the interface deliberately: adding a method to `AccountsPostingProviderInterface` would
 * make every test double answer it, and a mock returning null from a `: int` method fails for the
 * wrong reason. A static choke point cannot be mocked away, which is the point.
 */
final class ChartOfAccounts
{
    private function __construct() {}

    /**
     * Resolve a code that the posting cannot proceed without.
     *
     * @param  string  $purpose  Human-readable description of what is being posted; it appears in
     *                           the exception so the operator knows which document was refused.
     *
     * @throws \DomainException when no active account carries that code
     */
    public static function requireId(AccountsPostingProviderInterface $posting, string $code, string $purpose): int
    {
        $id = $posting->accountIdByCode($code);

        if ($id !== null) {
            return $id;
        }

        throw new \DomainException(sprintf(
            "Cannot post %s: the chart of accounts has no account coded '%s'. "
            .'Create that account, or point accounts.defaults at the code you use, and post again. '
            .'Nothing was written and the document was left unposted.',
            $purpose,
            $code
        ));
    }

    /**
     * Resolve the first of several acceptable codes, for legs where the chart may legitimately
     * carry the value under either account (a purchase with no receipt is expensed, or capitalised
     * into Inventory when the chart has no purchase account).
     *
     * Prefers the codes in the order given. Throws only when *none* of them exist — which is the
     * case that used to skip the journal silently.
     *
     * @throws \DomainException when none of the codes resolve
     */
    public static function firstExistingId(AccountsPostingProviderInterface $posting, string $purpose, string ...$codes): int
    {
        foreach ($codes as $code) {
            $id = $posting->accountIdByCode($code);

            if ($id !== null) {
                return $id;
            }
        }

        $listed = implode(', ', array_map(static fn (string $code): string => "'{$code}'", $codes));

        throw new \DomainException(sprintf(
            'Cannot post %s: the chart of accounts has none of the accounts coded %s. '
            .'Create one of them, or point accounts.defaults at the codes you use, and post again. '
            .'Nothing was written and the document was left unposted.',
            $purpose,
            $listed
        ));
    }
}
