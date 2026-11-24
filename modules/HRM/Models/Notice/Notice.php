<?php

namespace Modules\HRM\Models\Notice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;

class Notice extends Model
{
    protected $table = 'hrm_notices';

    protected $guarded = [];

    protected $casts = ['audience_ids' => 'array', 'is_active' => 'boolean', 'expire_at' => 'datetime'];

    public const AUDIENCES = ['all', 'department', 'employees'];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function scopeLive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('expire_at')->orWhere('expire_at', '>', now());
            });
    }
}
