<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission as PermissionModel;

class UpdateRolePermissions
{
    /** @param  array<int, string>  $permissionNames */
    public function handle(User $actor, Role $role, array $permissionNames): void
    {
        $unknown = array_diff($permissionNames, Permission::values());

        if ($unknown !== []) {
            throw new InvalidArgumentException('Permessi sconosciuti: '.implode(', ', $unknown));
        }

        $after = collect($permissionNames)->sort()->values()->all();

        // I nomi sono già validati contro l'enum: findOrCreate rende l'azione
        // indipendente dall'esecuzione del seeder.
        $permissions = array_map(
            fn (string $name) => PermissionModel::findOrCreate($name, 'web'),
            array_values($permissionNames),
        );

        // Modifica e voce di audit nella stessa transazione: o entrambe o nessuna.
        LastRolesManagerGuard::protect(function () use ($actor, $role, $permissions, $after) {
            // Lo stato precedente si legge dopo il lock, così una modifica concorrente non va persa.
            $before = $role->permissions()->pluck('name')->sort()->values()->all();

            PrivilegedAccessGuard::ensureCanChangePermissions($actor, $before, $after);

            $role->syncPermissions($permissions);

            if ($after === $before) {
                return;
            }

            activity('rbac')
                ->causedBy($actor)
                ->performedOn($role)
                ->event('permissions.synced')
                ->withProperties(['old' => $before, 'attributes' => $after])
                ->log('Permessi del ruolo aggiornati');
        });
    }
}
