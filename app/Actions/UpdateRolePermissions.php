<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Role;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission as PermissionModel;

class UpdateRolePermissions
{
    /** @param  array<int, string>  $permissionNames */
    public function handle(Role $role, array $permissionNames): void
    {
        $unknown = array_diff($permissionNames, Permission::values());

        if ($unknown !== []) {
            throw new InvalidArgumentException('Permessi sconosciuti: '.implode(', ', $unknown));
        }

        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        // I nomi sono già validati contro l'enum: findOrCreate rende l'azione
        // indipendente dall'esecuzione del seeder.
        $permissions = array_map(
            fn (string $name) => PermissionModel::findOrCreate($name, 'web'),
            array_values($permissionNames),
        );

        // Modifica e voce di audit nella stessa transazione: o entrambe o nessuna.
        LastRolesManagerGuard::protect(function () use ($role, $permissions, $permissionNames, $before) {
            $role->syncPermissions($permissions);

            activity('rbac')
                ->causedBy(auth()->user())
                ->performedOn($role)
                ->event('permissions.synced')
                ->withProperties(['old' => $before, 'attributes' => collect($permissionNames)->sort()->values()->all()])
                ->log('Permessi del ruolo aggiornati');
        });
    }
}
