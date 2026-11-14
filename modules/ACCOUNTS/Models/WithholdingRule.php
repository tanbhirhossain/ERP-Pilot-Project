<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * F17 — a configurable TDS/VDS withholding rate. `threshold` is the minimum
 * base amount below which no deduction is required (many TDS sections have one).
 */
class WithholdingRule extends Model
{
    protected $table = 'tax_withholding_rules';

    protected $fillable = ['name', 'type', 'section', 'rate', 'threshold', 'is_active', 'effective_from', 'notes'];

    protected $casts = [
        'rate' => 'decimal:2',
        'threshold' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'date',
    ];

    public function withholdings(): HasMany
    {
        return $this->hasMany(Withholding::class, 'rule_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
