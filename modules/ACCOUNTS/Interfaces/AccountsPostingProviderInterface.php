<?php

namespace Modules\ACCOUNTS\Interfaces;

use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\Models\Journal;

/**
 * DIP: SALES/POS/INVENTORY/PAYROLL depend on this interface, not concrete JournalService.
 * Allows ACCOUNTS to be swapped/tested without those modules knowing internals.
 */
interface AccountsPostingProviderInterface
{
    /**
     * Post a balanced journal. Returns posted Journal.
     * @throws \DomainException on fiscal lock / unbalanced / inactive account
     */
    public function postJournal(JournalData $data): Journal;

    /**
     * Post a simple double-entry: debitAccount -> creditAccount.
     */
    public function postSimple(string $date, string $type, int $debitAccountId, int $creditAccountId, float $amount, string $narration, ?string $refNo = null, ?string $sourceType = null, ?int $sourceId = null): Journal;

    public function voidJournal(int $journalId, string $reason): Journal;

    public function accountIdByCode(string $code): ?int;
    public function accountBalance(int $accountId, ?string $asOfDate = null): float;
}
