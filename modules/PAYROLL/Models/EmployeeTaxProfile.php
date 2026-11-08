<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTaxProfile extends Model
{
    protected $table = 'employee_tax_profiles';

    protected $guarded = [];

    protected $casts = ['fixed_tax_amount' => 'decimal:2', 'effective_from' => 'date', 'effective_to' => 'date'];
}
