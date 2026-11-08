<?php

namespace Modules\PARTNER\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PartnerContact extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'can_receive_statement' => 'boolean',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('partner_contact')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "PartnerContact {$e}");
    }
}
