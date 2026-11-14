<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollCutlist extends Model
{
    protected $table = 'payroll_cutlists';

    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime', 'approved_at' => 'datetime', 'approved_by' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(PayrollCutlistItem::class);
    }

    public function salarySheets(): HasMany
    {
        return $this->hasMany(PayrollSalarySheet::class);
    }
}
