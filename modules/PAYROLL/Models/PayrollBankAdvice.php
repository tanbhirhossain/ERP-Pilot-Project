<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollBankAdvice extends Model
{
    protected $table = 'payroll_bank_advices';
    protected $guarded = [];
    protected $casts = [
        'total_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public function salarySheet(): BelongsTo { return $this->belongsTo(PayrollSalarySheet::class, 'salary_sheet_id'); }
    public function bank(): BelongsTo { return $this->belongsTo(\Modules\ACCOUNTS\Models\Bank::class, 'bank_id'); }
    public function items(): HasMany { return $this->hasMany(PayrollBankAdviceItem::class, 'advice_id'); }
    public function journalPost(): BelongsTo { return $this->belongsTo(PayrollJournalPost::class, 'payroll_journal_post_id'); }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function periodLabel(): string { return sprintf('%04d-%02d', $this->pay_year, $this->pay_month); }
    public function adviceNo(): string { return $this->advice_no; }
}
