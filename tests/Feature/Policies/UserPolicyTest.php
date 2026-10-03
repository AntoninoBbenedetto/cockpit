<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

it('denies every user ability without the permission', function (string $ability) {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect($user->can($ability, $target))->toBeFalse();
})->with(['view', 'update', 'delete', 'suspend']);

it('denies class-level abilities without the permission', function (string $ability) {
    expect(User::factory()->create()->can($ability, User::class))->toBeFalse();
})->with(['viewAny', 'create']);

it('grants each ability with exactly its permission', function (string $ability, Permission $permission) {
    $user = userWith($permission);
    $target = User::factory()->create();

    expect($user->can($ability, $target))->toBeTrue();
})->with([
    ['view', Permission::UsersView],
    ['update', Permission::UsersUpdate],
    ['delete', Permission::UsersDelete],
    ['suspend', Permission::UsersSuspend],
]);

it('does not grant an ability with a different permission', function () {
    $user = userWith(Permission::UsersView);
    $target = User::factory()->create();

    expect($user->can('update', $target))->toBeFalse();
});

it('does not let a user delete or suspend themselves', function () {
    $user = userWith(Permission::UsersDelete, Permission::UsersSuspend);

    expect($user->can('delete', $user))->toBeFalse()
        ->and($user->can('suspend', $user))->toBeFalse();
});

it('never grants bulk delete', function () {
    $user = userWith(Permission::UsersDelete);

    expect($user->can('deleteAny', User::class))->toBeFalse();
});

it('does not grant access because of a role name', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Amministratore', 'web'));
    $target = User::factory()->create();

    expect($user->can('viewAny', User::class))->toBeFalse()
        ->and($user->can('update', $target))->toBeFalse()
        ->and($user->can('suspend', $target))->toBeFalse();
});
