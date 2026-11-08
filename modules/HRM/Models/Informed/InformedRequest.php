<?php

namespace Modules\HRM\Models\Informed;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Models\Employee\Employee;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InformedRequest extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'informed_requests';

    protected $guarded = [];

    protected $casts = ['informed_date' => 'date', 'expected_time' => 'string', 'approved_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_informed')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => class_basename($this).' '.$e);
    }
}
