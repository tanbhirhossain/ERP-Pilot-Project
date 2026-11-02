<?php

namespace Modules\HRM\Models\Employee;

use Illuminate\Database\Eloquent\Model;

final class EmployeeFallbackOffDay extends Model
{
    protected $table = 'employee_fallback_off_days';
    protected $guarded = [];
    protected $casts = ['day_of_week' => 'integer'];
}
