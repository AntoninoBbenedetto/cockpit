<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;

class DeleteRole
{
    public function handle(User $actor, Role $role): void
    {
        $name = $role->name;

        // Eliminazione e voce di audit nella stessa transazione: o entrambe o nessuna.
        LastRolesManagerGuard::protect(function () use ($actor, $role, $name) {
            PrivilegedAccessGuard::ensureCanDeleteRole($actor, $role);

            $role->delete();

            activity('rbac')
                ->causedBy($actor)
                ->event('role.deleted')
                ->withProperties(['name' => $name])
                ->log('Ruolo eliminato');
        });
    }
}
