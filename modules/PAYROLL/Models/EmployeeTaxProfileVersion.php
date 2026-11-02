<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTaxProfileVersion extends Model
{
    protected $table = 'payroll_employee_tax_profile_versions';
    protected $guarded = [];
    protected $casts = [
        'fixed_tax_amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];
}
