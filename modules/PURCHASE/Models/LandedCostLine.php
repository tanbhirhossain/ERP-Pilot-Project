<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostLine extends Model
{
    protected $table = 'purchase_landed_cost_lines';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2'];
    public function landedCost(): BelongsTo { return $this->belongsTo(LandedCost::class, 'landed_cost_id'); }
}
