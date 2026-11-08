<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Journal extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'acc_journals';

    protected $fillable = [
        'journal_no', 'reference_no', 'journal_date', 'type', 'fiscal_year_id', 'fiscal_period_id',
        'narration', 'total_debit', 'total_credit', 'status', 'partner_id', 'partner_type',
        'source_type', 'source_id', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'journal_date' => 'date', 'posted_at' => 'datetime', 'voided_at' => 'datetime',
        'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_id')->orderBy('line_no');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(AccountLedger::class, 'journal_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isBalanced(): bool
    {
        return bccomp((string) $this->total_debit, (string) $this->total_credit, 2) === 0;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['journal_no', 'type', 'status', 'total_debit', 'total_credit'])->logOnlyDirty()->useLogName('journals');
    }
}
