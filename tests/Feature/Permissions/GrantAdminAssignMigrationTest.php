<?php

use App\Models\Role;
use Spatie\Permission\Models\Permission as PermissionModel;

function runAdminAssignMigration(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_10_04_000000_grant_admin_assign_to_roles_managers.php');
    $migration->{$direction}();
}

it('grants admin.assign to roles that already hold roles.manage and only to them', function () {
    $managers = privilegedRole('Gestori');
    $ordinary = Role::findOrCreate('Operatore', 'web');
    $ordinary->givePermissionTo(PermissionModel::findOrCreate('users.view', 'web'));

    runAdminAssignMigration();

    expect($managers->fresh()->hasPermissionTo('admin.assign'))->toBeTrue()
        ->and($ordinary->fresh()->hasPermissionTo('admin.assign'))->toBeFalse();
});

it('is idempotent', function () {
    $managers = privilegedRole('Gestori');

    runAdminAssignMigration();
    runAdminAssignMigration();

    expect($managers->permissions()->where('name', 'admin.assign')->count())->toBe(1);
});

it('does nothing on a fresh installation without roles.manage', function () {
    runAdminAssignMigration();

    expect(PermissionModel::where('name', 'admin.assign')->exists())->toBeFalse();
});

it('removes only the admin.assign permission on down', function () {
    $managers = privilegedRole('Gestori');
    runAdminAssignMigration();

    runAdminAssignMigration('down');

    expect(PermissionModel::where('name', 'admin.assign')->exists())->toBeFalse()
        ->and($managers->fresh()->hasPermissionTo('roles.manage'))->toBeTrue();
});
