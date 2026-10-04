<?php

namespace App\Actions;

use App\Models\Role;

class DeleteRole
{
    public function handle(Role $role): void
    {
        $name = $role->name;

        // Eliminazione e voce di audit nella stessa transazione: o entrambe o nessuna.
        LastRolesManagerGuard::protect(function () use ($role, $name) {
            $role->delete();

            activity('rbac')
                ->causedBy(auth()->user())
                ->event('role.deleted')
                ->withProperties(['name' => $name])
                ->log('Ruolo eliminato');
        });
    }
}
