<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payable extends Model
{
    protected $table = 'acc_payables';
    protected $fillable = ['partner_id', 'journal_id', 'bill_no', 'bill_date', 'due_date', 'amount', 'paid_amount', 'status'];
    protected $casts = ['bill_date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function journal(): BelongsTo { return $this->belongsTo(Journal::class, 'journal_id'); }
    public function scopeOpen($q) { return $q->whereIn('status', ['open', 'partial', 'overdue']); }
    public function balance(): float { return (float)$this->amount - (float)$this->paid_amount; }
}
