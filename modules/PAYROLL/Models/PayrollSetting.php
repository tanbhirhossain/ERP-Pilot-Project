<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollSetting extends Model
{
    protected $table = 'payroll_settings';
    protected $guarded = [];
    protected $casts = ['loan_approval_steps' => 'array'];
}
