<?php

namespace Modules\HRM\Models\Employee;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\EmployeePolicyFactory;

class EmployeePolicy extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['is_punishable' => 'boolean', 'auto_shift' => 'boolean', 'allow_fallback' => 'boolean', 'buffer_minutes' => 'integer'];

    protected static function newFactory()
    {
        return EmployeePolicyFactory::new();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
