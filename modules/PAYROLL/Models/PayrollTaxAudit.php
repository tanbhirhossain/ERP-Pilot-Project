<?php

namespace Modules\PAYROLL\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollTaxAudit extends Model
{
    protected $table = 'payroll_tax_audits';

    protected $guarded = [];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
