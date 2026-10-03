<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

it('denies every role ability without roles.manage', function (string $ability) {
    $user = userWith(Permission::UsersView, Permission::UsersUpdate);
    $role = Role::findOrCreate('Operatore', 'web');

    expect($user->can($ability, $role))->toBeFalse();
})->with(['view', 'update', 'delete']);

it('denies class-level role abilities without roles.manage', function (string $ability) {
    expect(User::factory()->create()->can($ability, Role::class))->toBeFalse();
})->with(['viewAny', 'create']);

it('grants every role ability with roles.manage', function (string $ability) {
    $user = userWith(Permission::RolesManage);
    $role = Role::findOrCreate('Operatore', 'web');

    expect($user->can($ability, $role))->toBeTrue();
})->with(['view', 'update', 'delete']);
