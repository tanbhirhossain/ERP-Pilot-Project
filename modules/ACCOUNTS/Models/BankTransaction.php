<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    protected $table = 'acc_bank_transactions';

    protected $fillable = ['bank_id', 'journal_id', 'type', 'amount', 'reference_no', 'transaction_date', 'status', 'description'];

    protected $casts = ['amount' => 'decimal:2', 'transaction_date' => 'date'];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }
}
