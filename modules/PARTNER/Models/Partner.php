<?php

namespace Modules\PARTNER\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CORE\Models\User;
use Modules\PARTNER\Database\Factories\PartnerFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Partner extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'meta' => 'array',
        'payment_terms_days' => 'integer',
    ];

    protected static function newFactory(): Factory
    {
        return PartnerFactory::new();
    }

    // Relations
    public function group(): BelongsTo
    {
        return $this->belongsTo(PartnerGroup::class, 'group_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PartnerContact::class);
    }

    public function primaryContact(): BelongsTo
    {
        // HasMany but we expose via method for convenience — use contacts()->where is_primary
        return $this->belongsTo(PartnerContact::class, 'id', 'partner_id'); // dummy — use helper instead
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(PartnerAddress::class);
    }

    public function banks(): HasMany
    {
        return $this->hasMany(PartnerBank::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(PartnerNote::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PartnerActivity::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // Scopes
    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeVendors($q)
    {
        return $q->whereIn('type', ['vendor', 'both']);
    }

    public function scopeCustomers($q)
    {
        return $q->whereIn('type', ['customer', 'both']);
    }

    public function scopeVerified($q)
    {
        return $q->where('is_verified', true);
    }

    // Helpers
    public function isVendor(): bool
    {
        return in_array($this->type, ['vendor', 'both'], true);
    }

    public function isCustomer(): bool
    {
        return in_array($this->type, ['customer', 'both'], true);
    }

    public function creditUtilizationPct(): float
    {
        if ((float) $this->credit_limit <= 0) {
            return 0;
        }

        return round(((float) $this->current_balance / (float) $this->credit_limit) * 100, 2);
    }

    public function isCreditExceeded(): bool
    {
        if ((float) $this->credit_limit <= 0) {
            return false;
        }

        return (float) $this->current_balance > (float) $this->credit_limit;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('partner')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Partner {$e}");
    }
}
