<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $table = 'acc_journal_lines';
    protected $fillable = ['journal_id', 'account_id', 'debit', 'credit', 'description', 'partner_id', 'partner_type', 'cost_center', 'line_no'];
    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'line_no' => 'integer'];

    public function journal(): BelongsTo { return $this->belongsTo(Journal::class, 'journal_id'); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class, 'account_id'); }

    public function isDebit(): bool { return (float) $this->debit > 0; }
    public function amount(): float { return (float) ($this->debit ?: $this->credit); }
}
