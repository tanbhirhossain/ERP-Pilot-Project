<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;

class PosSale extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pos_sales';

    protected $fillable = ['pos_no', 'session_id', 'invoice_id', 'customer_id', 'customer_name', 'warehouse_id', 'sale_date', 'subtotal', 'discount_amount', 'vat_amount', 'total_amount', 'paid_amount', 'due_amount', 'collected_amount', 'change_amount', 'payment_method', 'cash_amount', 'bank_amount', 'cost_total', 'bank_id', 'status', 'notes', 'created_by'];

    protected $casts = [
        'sale_date' => 'date', 'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2', 'collected_amount' => 'decimal:2', 'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'change_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2', 'bank_amount' => 'decimal:2', 'cost_total' => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosSaleItem::class, 'pos_sale_id');
    }

    /** Payments taken against this sale's outstanding due, at this or a later till. */
    public function collections(): HasMany
    {
        return $this->hasMany(PosCollection::class, 'pos_sale_id');
    }

    /** What is still owed on a credit sale, in cents, so it can be compared exactly. */
    public function outstandingDueCents(): int
    {
        return (int) round(((float) $this->due_amount - (float) $this->collected_amount) * 100);
    }
}
