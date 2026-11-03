<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    protected $table = 'acc_fiscal_periods';
    protected $fillable = ['fiscal_year_id', 'name', 'month_no', 'start_date', 'end_date', 'status'];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'month_no' => 'integer'];
    public $timestamps = true;

    public function fiscalYear(): BelongsTo { return $this->belongsTo(FiscalYear::class, 'fiscal_year_id'); }
    public function isOpen(): bool { return $this->status === 'open'; }
    public function isLocked(): bool { return $this->status === 'locked'; }
}
