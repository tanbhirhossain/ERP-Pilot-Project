<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * F18 — a request for quotation: one subject, N vendors, one round.
 *
 * `awarded_quote_id` + `awarded_at` are the decision, and they are what makes the round *closed* in a
 * way the UI cannot forget: while they are null the compare screen is still live, and once set, a second
 * award is a DomainException rather than a second PO. An RFQ with no decision but a passed `due_on` is
 * still open — a late quote that arrives before anyone awards is a quote someone should see, not one to
 * silently drop.
 */
class PurchaseRfq extends Model
{
    use LogsActivity;

    protected $table = 'purchase_rfqs';

    protected $fillable = [
        'rfq_no', 'requisition_id', 'subject', 'status', 'due_on', 'sent_at', 'notes',
        'awarded_quote_id', 'awarded_at', 'awarded_by', 'created_by',
    ];

    protected $casts = [
        'due_on' => 'date',
        'sent_at' => 'datetime',
        'awarded_at' => 'datetime',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'requisition_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRfqLine::class, 'rfq_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(PurchaseRfqQuote::class, 'rfq_id');
    }

    public function awards(): HasMany
    {
        return $this->hasMany(PurchaseRfqAward::class, 'rfq_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isAwardable(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'closed'], true) && $this->awarded_quote_id === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('purchase_rfq')
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $e): string => "RFQ {$e}");
    }
}
