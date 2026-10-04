<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;

it('names the new permission admin.assign', function () {
    expect(Permission::AdminAssign->value)->toBe('admin.assign')
        ->and(Permission::privilegedValues())->toBe(['roles.manage', 'admin.assign']);
});

it('treats a role holding roles.manage or admin.assign as privileged', function (Permission $permission) {
    expect(privilegedRole('Gestori', $permission)->isPrivileged())->toBeTrue();
})->with([Permission::RolesManage, Permission::AdminAssign]);

it('does not treat an ordinary role as privileged', function () {
    $role = Role::findOrCreate('Operatore', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::UsersView->value, 'web'));

    expect($role->isPrivileged())->toBeFalse()
        ->and(Role::findOrCreate('Vuoto', 'web')->isPrivileged())->toBeFalse();
});

it('knows whether a user holds a privileged role', function () {
    $privileged = User::factory()->create();
    $privileged->assignRole(privilegedRole());

    $ordinary = User::factory()->create();
    $ordinary->assignRole(Role::findOrCreate('Operatore', 'web'));

    expect($privileged->hasPrivilegedRole())->toBeTrue()
        ->and($ordinary->hasPrivilegedRole())->toBeFalse()
        ->and(User::factory()->create()->hasPrivilegedRole())->toBeFalse();
});

it('does not count permissions given directly to the user', function () {
    expect(userWith(Permission::RolesManage)->hasPrivilegedRole())->toBeFalse();
});

it('gives admin.assign to Amministratore when the seeder creates the role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('Amministratore', 'web')->isPrivileged())->toBeTrue()
        ->and(Role::findByName('Amministratore', 'web')->hasPermissionTo(Permission::AdminAssign->value))->toBeTrue();
});
