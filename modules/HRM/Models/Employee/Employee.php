<?php

namespace Modules\HRM\Models\Employee;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Factories\EmployeeFactory;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\ClearanceTask;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\EmployeeAsset;
use Modules\HRM\Models\EmployeeChecklist;
use Modules\HRM\Models\EmployeeDocument;
use Modules\HRM\Models\EmployeeMovement;
use Modules\HRM\Models\EmployeeProbation;
use Modules\HRM\Models\Organization;
use Modules\HRM\Models\Roster\RosterDuty;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = [];

    protected $casts = ['joining_date' => 'date', 'date_of_birth' => 'date', 'is_active' => 'boolean', 'joining_salary' => 'decimal:2'];

    protected static function newFactory()
    {
        return EmployeeFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class, 'employee_id', 'employee_id');
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class, 'employee_id', 'employee_id');
    }

    public function currentSalary(): HasOne
    {
        return $this->hasOne(EmployeeSalary::class, 'employee_id', 'employee_id')->where('is_current', true)->latestOfMany();
    }

    public function educations(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class, 'employee_id', 'employee_id');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(EmployeeExperience::class, 'employee_id', 'employee_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_id', 'employee_id');
    }

    public function policy(): HasOne
    {
        return $this->hasOne(EmployeePolicy::class, 'employee_id', 'employee_id');
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(EmployeeSupervisor::class, 'employee_id', 'employee_id');
    }

    public function rosterDuties(): HasMany
    {
        return $this->hasMany(RosterDuty::class, 'employee_id', 'employee_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'employee_id', 'employee_id');
    }

    public function attendancePairs(): HasMany
    {
        return $this->hasMany(AttendancePair::class, 'employee_id', 'employee_id');
    }

    public function attendanceDailies(): HasMany
    {
        return $this->hasMany(AttendanceDaily::class, 'employee_id', 'employee_id');
    }

    public function deviceUsers(): HasMany
    {
        return $this->hasMany(DeviceUser::class, 'employee_id', 'employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id', 'employee_id');
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(EmployeeAsset::class, 'employee_id', 'employee_id');
    }

    public function activeAssets(): HasMany
    {
        return $this->hasMany(EmployeeAsset::class, 'employee_id', 'employee_id')->whereNull('returned_date');
    }

    public function probations(): HasMany
    {
        return $this->hasMany(EmployeeProbation::class, 'employee_id', 'employee_id');
    }

    public function activeProbation(): HasMany
    {
        return $this->hasMany(EmployeeProbation::class, 'employee_id', 'employee_id')->whereIn('status', ['Active', 'Extended']);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(EmployeeMovement::class, 'employee_id', 'employee_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(EmployeeChecklist::class, 'employee_id', 'employee_id');
    }

    public function clearanceTasks(): HasMany
    {
        return $this->hasMany(ClearanceTask::class, 'employee_id', 'employee_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_employee')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $event): string => class_basename($this).' '.$event);
    }
}
