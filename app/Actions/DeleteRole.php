<?php

namespace App\Actions;

use Spatie\Permission\Models\Role;

class DeleteRole
{
    public function handle(Role $role): void
    {
        $name = $role->name;

        LastRolesManagerGuard::protect(fn () => $role->delete());

        activity('rbac')
            ->event('role.deleted')
            ->withProperties(['name' => $name])
            ->log('Ruolo eliminato');
    }
}
