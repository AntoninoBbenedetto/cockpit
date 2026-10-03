<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(Permission::cases())
            ->map(fn (Permission $permission) => PermissionModel::findOrCreate($permission->value, 'web'));

        $role = Role::findOrCreate('Amministratore', 'web');
        $role->syncPermissions($permissions);
    }
}
