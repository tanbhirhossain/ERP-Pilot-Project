<?php

namespace Modules\PARTNER\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PartnerAddress extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function getFullAddressAttribute(): string
    {
        return collect([$this->address_line, $this->area, $this->city, $this->district, $this->division, $this->country])
            ->filter()->implode(', ');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('partner_address')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "PartnerAddress {$e}");
    }
}
