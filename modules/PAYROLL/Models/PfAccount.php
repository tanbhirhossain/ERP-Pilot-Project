<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HRM\Models\Employee\Employee;

class PfAccount extends Model
{
    protected $table = 'pf_accounts';
    protected $guarded = [];
    protected $casts = ['eligible_from' => 'date', 'employee_balance' => 'decimal:2', 'employer_balance' => 'decimal:2'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class, 'employee_id', 'employee_id'); }
    public function policy(): BelongsTo { return $this->belongsTo(PfPolicy::class, 'pf_policy_id'); }
    public function ledgers(): HasMany { return $this->hasMany(PfLedger::class)->orderBy('entry_date')->orderBy('id'); }
}
