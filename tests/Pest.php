<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Arch');

function userWith(Permission ...$permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(PermissionModel::findOrCreate($permission->value, 'web'));
    }

    return $user;
}

function privilegedRole(string $name = 'Gestori', Permission $permission = Permission::RolesManage): Role
{
    $role = Role::findOrCreate($name, 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate($permission->value, 'web'));

    return $role;
}
