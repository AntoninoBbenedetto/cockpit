<?php

namespace App\Actions;

use App\Enums\Permission;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

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

        LastRolesManagerGuard::protect(fn () => $role->syncPermissions($permissions));

        activity('rbac')
            ->performedOn($role)
            ->event('permissions.synced')
            ->withProperties(['old' => $before, 'attributes' => collect($permissionNames)->sort()->values()->all()])
            ->log('Permessi del ruolo aggiornati');
    }
}
