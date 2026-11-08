<?php

namespace Modules\HRM\Models\Employee;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\EmployeeSkillFactory;

class EmployeeSkill extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory()
    {
        return EmployeeSkillFactory::new();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
