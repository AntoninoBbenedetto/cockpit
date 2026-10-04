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

function helpdesk(): User
{
    return userWith(Permission::UsersView, Permission::UsersUpdate, Permission::UsersSuspend, Permission::UsersDelete);
}

it('denies a helpdesk actor every write on a target holding a permission the actor lacks', function (string $ability, Permission $extra) {
    $target = userWith($extra);

    expect(helpdesk()->can($ability, $target))->toBeFalse();
})->with(['update', 'suspend', 'delete'])->with([Permission::RolesManage, Permission::AuditView]);

it('denies a permission held only through a role', function () {
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate(Permission::RolesManage->value, 'web'));
    $target = User::factory()->create();
    $target->assignRole($role);

    $actor = helpdesk();

    expect($actor->can('update', $target))->toBeFalse()
        ->and($actor->can('suspend', $target))->toBeFalse()
        ->and($actor->can('delete', $target))->toBeFalse();
});

it('lets a helpdesk actor act on targets with no permissions, a peer or a subset', function (string $ability) {
    $actor = helpdesk();
    $none = User::factory()->create();
    $peer = helpdesk();
    $subset = userWith(Permission::UsersView, Permission::UsersSuspend);

    expect($actor->can($ability, $none))->toBeTrue()
        ->and($actor->can($ability, $peer))->toBeTrue()
        ->and($actor->can($ability, $subset))->toBeTrue();
})->with(['update', 'suspend', 'delete']);

it('lets an admin.assign holder act on anyone, with the self rules still applying', function () {
    $admin = userWith(...Permission::cases());
    $target = userWith(...Permission::cases());

    expect($admin->can('update', $target))->toBeTrue()
        ->and($admin->can('suspend', $target))->toBeTrue()
        ->and($admin->can('delete', $target))->toBeTrue()
        ->and($admin->can('update', $admin))->toBeTrue()
        ->and($admin->can('suspend', $admin))->toBeFalse()
        ->and($admin->can('delete', $admin))->toBeFalse();
});

it('denies a roles.manage holder without admin.assign every write on a user holding a privileged role', function (string $ability) {
    $target = User::factory()->create();
    $target->assignRole(privilegedRole());
    $actor = userWith(Permission::UsersView, Permission::UsersUpdate, Permission::UsersSuspend, Permission::UsersDelete, Permission::RolesManage);

    expect($actor->can($ability, $target))->toBeFalse();
})->with(['update', 'suspend', 'delete']);

it('lets an admin.assign holder act on a user holding a privileged role', function (string $ability) {
    $target = User::factory()->create();
    $target->assignRole(privilegedRole());
    $actor = userWith(Permission::UsersUpdate, Permission::UsersSuspend, Permission::UsersDelete, Permission::AdminAssign);

    expect($actor->can($ability, $target))->toBeTrue();
})->with(['update', 'suspend', 'delete']);

it('no longer lets roles.manage alone bypass the permission subset rule', function () {
    $actor = userWith(Permission::UsersUpdate, Permission::RolesManage);
    $target = userWith(Permission::AuditView);

    expect($actor->can('update', $target))->toBeFalse();
});
