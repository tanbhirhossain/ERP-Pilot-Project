<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * F15 — one depreciation posting per accounting period (YYYY-MM).
 *
 * A run is all-or-nothing: it writes its per-asset lines and a single balanced journal inside one
 * transaction. The `journal_id` is the audit thread back to the ledger, and the unique `period` is
 * the second idempotency guard on top of the per-(asset, period) line constraint.
 */
class DepreciationRun extends Model
{
    protected $table = 'acc_depreciation_runs';

    protected $fillable = [
        'run_no', 'period', 'run_date', 'total_amount', 'line_count',
        'journal_id', 'status', 'notes', 'created_by', 'posted_at',
    ];

    protected $casts = [
        'run_date' => 'date',
        'posted_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'line_count' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class, 'run_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }
}
