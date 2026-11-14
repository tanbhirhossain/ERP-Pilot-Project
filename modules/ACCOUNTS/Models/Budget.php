<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * F14 — a fiscal-year budget (optionally department-scoped).
 *
 * The plan itself lives in its `lines`; the header carries the period container (the fiscal year)
 * and the sign-off state. A budget must be `approved` for the variance report to treat it as the
 * committed plan, which keeps a half-entered draft from being read as an official target.
 */
class Budget extends Model
{
    protected $table = 'acc_budgets';

    protected $fillable = [
        'name', 'code', 'fiscal_year_id', 'department', 'currency',
        'status', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' || $this->status === 'locked';
    }

    public function scopeApproved($q)
    {
        return $q->whereIn('status', ['approved', 'locked']);
    }
}
