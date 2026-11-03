<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class EmployeeMovement extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'hrm_employee_movements';
    protected $guarded = [];
    protected $casts = ['effective_date'=>'date','from_salary'=>'decimal:2','to_salary'=>'decimal:2','increment_amount'=>'decimal:2','approved_at'=>'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function fromDepartment(): BelongsTo { return $this->belongsTo(Department::class, 'from_department_id'); }
    public function toDepartment(): BelongsTo { return $this->belongsTo(Department::class, 'to_department_id'); }
    public function fromDesignation(): BelongsTo { return $this->belongsTo(Designation::class, 'from_designation_id'); }
    public function toDesignation(): BelongsTo { return $this->belongsTo(Designation::class, 'to_designation_id'); }
    public function isDraft(): bool { return $this->status === 'Draft'; }
    public function isPending(): bool { return $this->status === 'Pending'; }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_movement')->logAll()->logOnlyDirty(); }
}
