<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('permissions')
            ->logOnly(['name', 'guard_name'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $event): string => match ($event) {
                'created' => 'Permission created',
                'updated' => 'Permission updated',
                'deleted' => 'Permission deleted',
                default => 'Permission '.$event,
            });
    }

    /**
     * Human friendly group derived from the "resource.action" convention.
     */
    public function getGroupAttribute(): string
    {
        $segments = explode('.', (string) $this->name);

        return count($segments) > 1 ? ucfirst($segments[0]) : 'General';
    }
}
