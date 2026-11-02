<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FestivalBonusRun extends Model
{
    protected $table = 'festival_bonus_runs';
    protected $guarded = [];
    protected $casts = ['total_amount' => 'decimal:2'];

    public function policy(): BelongsTo { return $this->belongsTo(FestivalBonusPolicy::class, 'festival_bonus_policy_id'); }
    public function items(): HasMany { return $this->hasMany(FestivalBonusItem::class); }
}
