<?php

namespace App\Models;

use App\Enums\Permission;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use LogsActivity;

    /** Un ruolo è privilegiato se contiene roles.manage o admin.assign. */
    public function isPrivileged(): bool
    {
        return $this->permissions()->whereIn('name', Permission::privilegedValues())->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('role')
            ->logOnly(['name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
