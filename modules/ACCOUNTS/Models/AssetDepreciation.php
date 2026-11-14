<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F15 — one asset's depreciation for one period.
 *
 * A snapshot row, not a live balance: it records the opening NBV, the charge, and the resulting
 * running balances, so a period never has to be recomputed to explain the register. `basis` keeps
 * the formula that produced the charge, which is what makes a reviewable audit trail rather than a
 * black-box number.
 */
class AssetDepreciation extends Model
{
    protected $table = 'acc_asset_depreciations';

    protected $fillable = [
        'run_id', 'asset_id', 'period', 'opening_nbv', 'charge',
        'accumulated_after', 'closing_nbv', 'method', 'basis',
    ];

    protected $casts = [
        'opening_nbv' => 'decimal:2',
        'charge' => 'decimal:2',
        'accumulated_after' => 'decimal:2',
        'closing_nbv' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
