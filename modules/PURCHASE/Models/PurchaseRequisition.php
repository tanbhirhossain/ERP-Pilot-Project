<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Warehouse;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * F18 — a purchase requisition: an internal request to buy, before anyone has asked a vendor anything.
 *
 * What it is *not*: a commitment. Nothing on this model reaches the ledger, and it must not — the PO it
 * may eventually become is the commitment, and the whole value of the requisition is that a manager can
 * say no here, where saying no is free, rather than at the bill, where it is not.
 *
 * `estimated_amount` is a sum of line estimates, recomputed on every write, and it is deliberately named
 * *estimate*: it is what the requester believed, used only to route the approval (does this need a
 * second signature?) and to compare against what the market actually quoted.
 */
class PurchaseRequisition extends Model
{
    use LogsActivity;

    protected $table = 'purchase_requisitions';

    protected $fillable = [
        'req_no', 'title', 'requested_by', 'department', 'warehouse_id', 'need_by', 'justification',
        'account_id', 'estimated_amount', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
        'converted_order_id', 'created_by',
    ];

    protected $casts = [
        'need_by' => 'date',
        'reviewed_at' => 'datetime',
        'estimated_amount' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionItem::class, 'requisition_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The PO this became, if it did. Kept on both sides on purpose: the requisition answers "where did my request go?", the PO answers "why does this order exist?". */
    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'converted_order_id');
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(PurchaseRfq::class, 'requisition_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Open for an award: only an approved requisition can be bought against. */
    public function isAwardable(): bool
    {
        return $this->status === 'approved';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('purchase_requisition')
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $e): string => "requisition {$e}");
    }
}
