<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class EmployeeProbation extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'hrm_employee_probations';
    protected $guarded = [];
    protected $casts = ['probation_start'=>'date','probation_end'=>'date','extended_till'=>'date','confirmed_at'=>'date'];
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function isActive(): bool { return $this->status === 'Active' || $this->status === 'Extended'; }
    public function isOverdue(): bool {
        $deadline = $this->extended_till ?? $this->probation_end;
        return $this->isActive() && $deadline && $deadline->isPast();
    }
    public function daysRemaining(): int {
        $deadline = $this->extended_till ?? $this->probation_end;
        return $deadline ? (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false) : 0;
    }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_probation')->logAll()->logOnlyDirty(); }
}
