<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollLoanApproval extends Model
{
    protected $table = 'payroll_loan_approvals';
    protected $guarded = [];
    protected $casts = ['decided_at' => 'datetime'];
    public function loan(): BelongsTo { return $this->belongsTo(PayrollLoan::class, 'payroll_loan_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'actor_id'); }
}
