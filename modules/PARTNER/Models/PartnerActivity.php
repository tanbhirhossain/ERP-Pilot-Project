<?php

namespace Modules\PARTNER\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;

class PartnerActivity extends Model
{
    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
