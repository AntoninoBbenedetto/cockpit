<?php

use App\Enums\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;

it('creates every permission defined in the enum', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('gives the starting role every permission explicitly', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $role = Role::findByName('Amministratore', 'web');

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('is idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::count())->toBe(count(Permission::cases()))
        ->and(Role::count())->toBe(1);
});
