<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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

it('does not restore permissions removed from an existing Amministratore role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::findByName('Amministratore', 'web');
    $role->revokePermissionTo(Permission::AuditView->value);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect($role->fresh()->hasPermissionTo(Permission::AuditView->value))->toBeFalse()
        ->and(PermissionModel::count())->toBe(count(Permission::cases()));
});

it('creates a permission added to the enum later without touching the existing role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::findByName('Amministratore', 'web');
    $role->syncPermissions([]);
    PermissionModel::where('name', Permission::AuditView->value)->delete();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::count())->toBe(count(Permission::cases()))
        ->and($role->fresh()->permissions)->toHaveCount(0);
});

it('creates no user when DatabaseSeeder runs', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0)
        ->and(Role::where('name', 'Amministratore')->exists())->toBeTrue();
});
