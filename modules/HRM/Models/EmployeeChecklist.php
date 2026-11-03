<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class EmployeeChecklist extends Model
{
    use LogsActivity;
    protected $table = 'hrm_employee_checklists';
    protected $guarded = [];
    protected $casts = ['progress_pct'=>'decimal:2'];
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function template(): BelongsTo { return $this->belongsTo(ChecklistTemplate::class, 'template_id'); }
    public function movement(): BelongsTo { return $this->belongsTo(EmployeeMovement::class, 'movement_id'); }
    public function progress(): HasMany { return $this->hasMany(EmployeeChecklistProgress::class, 'employee_checklist_id'); }
    public function recalcProgress(): void
    {
        $total = $this->progress()->count();
        $done = $this->progress()->where('status','Done')->count();
        $pct = $total ? round($done / $total * 100, 2) : 0;
        $status = $pct >= 100 ? 'Completed' : ($done > 0 ? 'In Progress' : 'Pending');
        $this->update(['progress_pct'=>$pct,'status'=>$status]);
    }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_employee_checklist')->logAll()->logOnlyDirty(); }
}
