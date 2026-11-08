<?php

namespace Modules\HRM\Models\Employee;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Factories\EmployeeSupervisorFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeSupervisor extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    protected static function newFactory()
    {
        return EmployeeSupervisorFactory::new();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    // Legacy User supervisor
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function supervisorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    // Flexible Employee supervisor (employee can be supervisor)
    public function supervisorEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id', 'employee_id');
    }

    // Accessor: unified supervisor display
    public function getSupervisorDisplayAttribute(): array
    {
        if ($this->supervisor_employee_id && $this->relationLoaded('supervisorEmployee') && $this->supervisorEmployee) {
            $e = $this->supervisorEmployee;

            return [
                'kind' => 'employee',
                'id' => $e->employee_id,
                'name' => $e->full_name ?? trim($e->first_name.' '.$e->last_name),
                'email' => $e->email,
                'department' => $e->department?->name,
            ];
        }
        if ($this->supervisor_id && $this->relationLoaded('supervisor') && $this->supervisor) {
            $u = $this->supervisor;

            return [
                'kind' => 'user',
                'id' => (string) $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ];
        }
        // Fallback if relations not loaded
        if ($this->supervisor_employee_id) {
            return ['kind' => 'employee', 'id' => $this->supervisor_employee_id, 'name' => $this->supervisor_employee_id, 'email' => null];
        }
        if ($this->supervisor_id) {
            return ['kind' => 'user', 'id' => (string) $this->supervisor_id, 'name' => 'User #'.$this->supervisor_id, 'email' => null];
        }

        return ['kind' => 'none', 'id' => null, 'name' => '—', 'email' => null];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopePrimary($q)
    {
        return $q->where('is_primary', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_supervisor')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $event): string => class_basename($this)." $event");
    }
}
