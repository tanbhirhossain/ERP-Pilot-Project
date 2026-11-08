<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountLedger extends Model
{
    protected $table = 'acc_account_ledger';

    protected $fillable = ['account_id', 'journal_id', 'journal_line_id', 'posting_date', 'debit', 'credit', 'balance_after', 'reference_no'];

    protected $casts = ['posting_date' => 'date', 'debit' => 'decimal:2', 'credit' => 'decimal:2', 'balance_after' => 'decimal:2'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
