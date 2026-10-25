<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('roles')
            ->logOnly(['name', 'guard_name'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $event): string => match ($event) {
                'created' => 'Role created',
                'updated' => 'Role updated',
                'deleted' => 'Role deleted',
                default => 'Role '.$event,
            });
    }
}
