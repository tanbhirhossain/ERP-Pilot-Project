<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F15 — a fixed-asset class.
 *
 * Carries the three ledger accounts every depreciation posting needs and the defaults an asset in
 * this class inherits when it does not override them (method, life, salvage %, declining-balance
 * factor). The account ids are required and validated as posting accounts by the engine, so a
 * half-configured category can never post a lopsided journal.
 */
class AssetCategory extends Model
{
    protected $table = 'acc_asset_categories';

    protected $fillable = [
        'name', 'code', 'asset_account_id', 'accum_dep_account_id', 'dep_expense_account_id',
        'default_method', 'default_useful_life_years', 'default_salvage_pct', 'default_db_factor',
        'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'default_salvage_pct' => 'decimal:2',
        'default_db_factor' => 'decimal:2',
        'default_useful_life_years' => 'integer',
        'is_active' => 'boolean',
    ];

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumDepAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accum_dep_account_id');
    }

    public function depExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'dep_expense_account_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
