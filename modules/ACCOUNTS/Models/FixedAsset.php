<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\PARTNER\Models\Partner;

/**
 * F15 — one depreciable asset in the register.
 *
 * `accumulated_dep` and `net_book_value` are running balances written by the depreciation engine
 * (and initialised to `cost` at capitalisation); `method` / `useful_life_years` / `db_factor` are
 * nullable and fall back to the category defaults. `status` is the switch that stops a disposed
 * asset from depreciating; a run only ever touches rows in `in_use`.
 */
class FixedAsset extends Model
{
    use SoftDeletes;

    protected $table = 'acc_fixed_assets';

    protected $fillable = [
        'asset_code', 'name', 'category_id', 'supplier_partner_id', 'invoice_ref',
        'purchase_date', 'in_service_date', 'cost', 'salvage_value', 'method',
        'useful_life_years', 'db_factor', 'accumulated_dep', 'net_book_value', 'status',
        'location', 'disposed_date', 'disposal_proceeds', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'in_service_date' => 'date',
        'disposed_date' => 'date',
        'cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'accumulated_dep' => 'decimal:2',
        'net_book_value' => 'decimal:2',
        'disposal_proceeds' => 'decimal:2',
        'db_factor' => 'decimal:2',
        'useful_life_years' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_partner_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class, 'asset_id')->orderBy('period');
    }

    /** Assets a run may charge: capitalised, not yet fully written down. */
    public function scopeDepreciable(Builder $q): Builder
    {
        return $q->where('status', 'in_use');
    }

    public function isDepreciable(): bool
    {
        return $this->status === 'in_use';
    }

    /** The most that can ever be depreciated off this asset. */
    public function depreciableBase(): float
    {
        return max(0, round((float) $this->cost - (float) $this->salvage_value, 2));
    }
}
