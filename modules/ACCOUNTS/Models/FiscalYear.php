<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FiscalYear extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'acc_fiscal_years';
    protected $fillable = ['name', 'code', 'start_date', 'end_date', 'status', 'closed_by', 'closed_at', 'notes', 'created_by'];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime'];

    public function periods(): HasMany { return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id')->orderBy('month_no'); }
    public function journals(): HasMany { return $this->hasMany(Journal::class, 'fiscal_year_id'); }

    public function isOpen(): bool { return $this->status === 'open'; }
    public function isClosed(): bool { return $this->status === 'closed'; }
    public function containsDate(string $date): bool { return $date >= $this->start_date->format('Y-m-d') && $date <= $this->end_date->format('Y-m-d'); }
}
