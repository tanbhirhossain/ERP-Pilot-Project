<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receivable extends Model
{
    protected $table = 'acc_receivables';
    protected $fillable = ['partner_id', 'journal_id', 'invoice_no', 'invoice_date', 'due_date', 'amount', 'received_amount', 'status'];
    protected $casts = ['invoice_date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'received_amount' => 'decimal:2'];

    public function journal(): BelongsTo { return $this->belongsTo(Journal::class, 'journal_id'); }
    public function scopeOpen($q) { return $q->whereIn('status', ['open', 'partial', 'overdue']); }
}
