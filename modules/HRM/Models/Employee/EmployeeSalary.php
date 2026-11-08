<?php

namespace Modules\HRM\Models\Employee;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\EmployeeSalaryFactory;

class EmployeeSalary extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'is_current' => 'boolean', 'basic_salary' => 'decimal:2', 'gross_salary' => 'decimal:2', 'net_salary' => 'decimal:2'];

    protected static function newFactory()
    {
        return EmployeeSalaryFactory::new();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
