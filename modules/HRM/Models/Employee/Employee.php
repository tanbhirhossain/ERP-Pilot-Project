<?php
namespace Modules\HRM\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
class Employee extends Model {
    use HasFactory, SoftDeletes, LogsActivity;
    protected $guarded = [];
    protected $casts = ['joining_date'=>'date','date_of_birth'=>'date','is_active'=>'boolean'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\EmployeeFactory::new(); }
    public function organization(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Organization::class); }
    public function department(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Department::class); }
    public function designation(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Designation::class); }
    public function user(): BelongsTo { return $this->belongsTo(\Modules\CORE\Models\User::class, 'user_id'); }
    public function profile(): HasOne { return $this->hasOne(EmployeeProfile::class, 'employee_id','employee_id'); }
    public function salaries(): HasMany { return $this->hasMany(EmployeeSalary::class, 'employee_id','employee_id'); }
    public function currentSalary(): HasOne { return $this->hasOne(EmployeeSalary::class, 'employee_id','employee_id')->where('is_current',true)->latestOfMany(); }
    public function educations(): HasMany { return $this->hasMany(EmployeeEducation::class, 'employee_id','employee_id'); }
    public function experiences(): HasMany { return $this->hasMany(EmployeeExperience::class, 'employee_id','employee_id'); }
    public function skills(): HasMany { return $this->hasMany(EmployeeSkill::class, 'employee_id','employee_id'); }
    public function policy(): HasOne { return $this->hasOne(EmployeePolicy::class, 'employee_id','employee_id'); }
    public function supervisors(): HasMany { return $this->hasMany(EmployeeSupervisor::class, 'employee_id','employee_id'); }
    public function rosterDuties(): HasMany { return $this->hasMany(\Modules\HRM\Models\Roster\RosterDuty::class, 'employee_id','employee_id'); }
    public function attendanceLogs(): HasMany { return $this->hasMany(\Modules\HRM\Models\Attendance\AttendanceLog::class, 'employee_id','employee_id'); }
    public function attendancePairs(): HasMany { return $this->hasMany(\Modules\HRM\Models\Attendance\AttendancePair::class, 'employee_id','employee_id'); }
    public function attendanceDailies(): HasMany { return $this->hasMany(\Modules\HRM\Models\Attendance\AttendanceDaily::class, 'employee_id','employee_id'); }
    public function deviceUsers(): HasMany { return $this->hasMany(\Modules\HRM\Models\DeviceUser::class, 'employee_id','employee_id'); }
    public function documents(): HasMany { return $this->hasMany(\Modules\HRM\Models\EmployeeDocument::class, 'employee_id','employee_id'); }
    public function assetAssignments(): HasMany { return $this->hasMany(\Modules\HRM\Models\EmployeeAsset::class, 'employee_id','employee_id'); }
    public function activeAssets(): HasMany { return $this->hasMany(\Modules\HRM\Models\EmployeeAsset::class, 'employee_id','employee_id')->whereNull('returned_date'); }
    public function probations(): HasMany { return $this->hasMany(\Modules\HRM\Models\EmployeeProbation::class, 'employee_id','employee_id'); }
    public function activeProbation(): HasMany { return $this->hasMany(\Modules\HRM\Models\EmployeeProbation::class, 'employee_id','employee_id')->whereIn('status',['Active','Extended']); }
    public function movements(): HasMany { return $this->hasMany(\Modules\HRM\Models\EmployeeMovement::class, 'employee_id','employee_id'); }
    public function checklists(): HasMany { return $this->hasMany(\Modules\HRM\Models\EmployeeChecklist::class, 'employee_id','employee_id'); }
    public function clearanceTasks(): HasMany { return $this->hasMany(\Modules\HRM\Models\ClearanceTask::class, 'employee_id','employee_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_employee')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event): string => class_basename($this).' '.$event);
    }
}
