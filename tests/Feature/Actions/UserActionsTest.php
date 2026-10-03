<?php

use App\Actions\DeleteUser;
use App\Actions\ReactivateUser;
use App\Actions\SuspendUser;
use App\Actions\SyncUserRoles;
use App\Actions\UpdateRolePermissions;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

it('suspends and reactivates a user', function () {
    $actor = userWith(Permission::UsersSuspend);
    $target = User::factory()->create();

    app(SuspendUser::class)->handle($actor, $target);
    expect($target->fresh()->status)->toBe(UserStatus::Suspended);

    app(ReactivateUser::class)->handle($target);
    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('refuses to suspend or delete oneself', function () {
    $user = userWith(Permission::UsersSuspend, Permission::UsersDelete);

    expect(fn () => app(SuspendUser::class)->handle($user, $user))->toThrow(DomainException::class)
        ->and(fn () => app(DeleteUser::class)->handle($user, $user))->toThrow(DomainException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('syncs the roles of a user', function () {
    Role::findOrCreate('Operatore', 'web');
    Role::findOrCreate('Lettore', 'web');
    $target = User::factory()->create();

    app(SyncUserRoles::class)->handle(userWith(Permission::RolesManage), $target, ['Operatore', 'Lettore']);

    expect($target->fresh()->roles->pluck('name')->sort()->values()->all())
        ->toBe(['Lettore', 'Operatore']);
});

it('updates the permissions of a role', function () {
    $role = Role::findOrCreate('Operatore', 'web');

    app(UpdateRolePermissions::class)->handle($role, [Permission::UsersView->value]);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe([Permission::UsersView->value]);
});

it('rejects permission names that are not in the enum', function () {
    $role = Role::findOrCreate('Operatore', 'web');

    expect(fn () => app(UpdateRolePermissions::class)->handle($role, ['users.fly']))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to sync roles for an actor without roles.manage and changes nothing', function () {
    Role::findOrCreate('Operatore', 'web');
    $actor = userWith(Permission::UsersUpdate);
    $target = User::factory()->create();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $target, ['Operatore']))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->roles)->toHaveCount(0);
});
