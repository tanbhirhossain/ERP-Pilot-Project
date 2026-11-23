<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PARTNER\Models\Partner;

class Payment extends Model
{
    protected $table = 'sales_payments';

    protected $fillable = ['payment_no', 'invoice_id', 'customer_id', 'journal_id', 'payment_date', 'amount', 'method', 'bank_id', 'reference_no', 'notes', 'status', 'created_by', 'voided_by', 'voided_at', 'void_reason'];

    protected $casts = ['payment_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'voided_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }
}
