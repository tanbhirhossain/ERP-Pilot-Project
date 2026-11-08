<?php

namespace Modules\PARTNER\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PartnerDocument extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'expiry_date' => 'date',
        'verified_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('partner_document')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "PartnerDocument {$e}");
    }
}
