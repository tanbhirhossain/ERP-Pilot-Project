<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePayment extends Model
{
    protected $table = 'purchase_payments';
    protected $guarded = [];
    protected $casts = ['payment_date'=>'date','amount'=>'decimal:2'];
    public function bill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'bill_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'vendor_id'); }
    public function journal(): BelongsTo { return $this->belongsTo(\Modules\ACCOUNTS\Models\Journal::class, 'journal_id'); }
}
