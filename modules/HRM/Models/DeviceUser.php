<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceUser extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];

    protected static function newFactory()
    {
        return \Modules\HRM\Database\Factories\DeviceUserFactory::new();
    }

    /**
     * Global ZK id (UserID) → HRM employee mapping. device_user_id = id, uid = internal index (never for mapping)
     * device_id is now nullable/ignored — one device_user_id maps to one employee globally.
     * This allows punch-in on Device A and punch-out on Device B.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id', 'employee_id');
    }

    /** Legacy: optional trace of which device the UID was first synced from. New rows use null. */
    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_device_user')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event): string => class_basename($this) . " $event");
    }
}
