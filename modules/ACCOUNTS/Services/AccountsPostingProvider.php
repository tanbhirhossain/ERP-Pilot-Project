<?php

namespace Modules\ACCOUNTS\Services;

use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Journal;

class AccountsPostingProvider implements AccountsPostingProviderInterface
{
    public function __construct(private readonly JournalService $journals, private readonly LedgerService $ledger) {}

    public function postJournal(JournalData $data): Journal
    {
        $journal = $this->journals->createFromDto($data);
        return $this->journals->post($journal);
    }

    public function postSimple(string $date, string $type, int $debitAccountId, int $creditAccountId, float $amount, string $narration, ?string $refNo = null, ?string $sourceType = null, ?int $sourceId = null): Journal
    {
        $dto = new JournalData(
            journalDate: $date, type: $type, referenceNo: $refNo, narration: $narration,
            lines: [
                new JournalLineData(accountId: $debitAccountId, debit: $amount),
                new JournalLineData(accountId: $creditAccountId, credit: $amount),
            ],
            sourceType: $sourceType, sourceId: $sourceId,
        );
        return $this->postJournal($dto);
    }

    public function voidJournal(int $journalId, string $reason): Journal
    {
        $journal = $this->journals->findOrFail($journalId);
        return $this->journals->void($journal, $reason);
    }

    public function accountIdByCode(string $code): ?int
    {
        return Account::where('code', $code)->value('id');
    }

    public function accountBalance(int $accountId, ?string $asOfDate = null): float
    {
        return $this->ledger->accountBalance($accountId, $asOfDate);
    }
}
