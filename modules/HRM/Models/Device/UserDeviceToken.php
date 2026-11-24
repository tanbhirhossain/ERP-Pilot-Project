<?php

namespace Modules\HRM\Models\Device;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;

class UserDeviceToken extends Model
{
    protected $table = 'user_device_tokens';

    protected $guarded = [];

    protected $casts = ['last_used_at' => 'datetime'];

    public const PLATFORMS = ['android', 'ios', 'web'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
