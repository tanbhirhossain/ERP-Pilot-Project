<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;

/**
 * F17 — one TDS/VDS deduction.
 *
 * `direction`:
 *   inward  — we withheld on a vendor bill/payment; a liability we deposit to NBR.
 *   outward — a customer withheld on our sale; a credit we claim in Mushak 9.1
 *             once the Mushak 6.6 certificate is received (`certified`).
 */
class Withholding extends Model
{
    protected $table = 'tax_withholdings';

    protected $fillable = ['type', 'direction', 'rule_id', 'partner_id', 'bill_id', 'invoice_id', 'period', 'base_amount', 'rate', 'amount', 'status', 'deposit_id', 'certificate_no', 'deposited_at', 'notes', 'created_by'];

    protected $casts = [
        'period' => 'date',
        'base_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'deposited_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WithholdingRule::class, 'rule_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'bill_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(TaxDeposit::class, 'deposit_id');
    }

    public function scopeInward($q)
    {
        return $q->where('direction', 'inward');
    }

    public function scopeOutward($q)
    {
        return $q->where('direction', 'outward');
    }

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }
}
