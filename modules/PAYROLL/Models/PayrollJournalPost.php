<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollJournalPost extends Model
{
    protected $table = 'payroll_journal_posts';
    protected $guarded = [];
    protected $casts = [
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'cost_center_breakdown' => 'array',
        'meta' => 'array',
    ];

    public function salarySheet(): BelongsTo { return $this->belongsTo(PayrollSalarySheet::class, 'salary_sheet_id'); }
    public function settlement(): BelongsTo { return $this->belongsTo(PayrollSettlement::class, 'settlement_id'); }
    public function journal(): BelongsTo { return $this->belongsTo(\Modules\ACCOUNTS\Models\Journal::class, 'journal_id'); }

    public function isPosted(): bool { return $this->status === 'posted'; }
    public function scopeForSheet($q, int $sheetId) { return $q->where('salary_sheet_id', $sheetId); }
}
