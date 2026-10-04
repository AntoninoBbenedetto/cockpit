<?php

use App\Actions\DeleteRole;
use App\Actions\DeleteUser;
use App\Actions\ReactivateUser;
use App\Actions\SuspendUser;
use App\Actions\SyncUserRoles;
use App\Actions\UpdateRolePermissions;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

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

    app(UpdateRolePermissions::class)->handle(User::factory()->create(), $role, [Permission::UsersView->value]);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe([Permission::UsersView->value]);
});

it('rejects permission names that are not in the enum', function () {
    $role = Role::findOrCreate('Operatore', 'web');

    expect(fn () => app(UpdateRolePermissions::class)->handle(User::factory()->create(), $role, ['users.fly']))
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

it('refuses to assign a privileged role without admin.assign and changes nothing', function () {
    $role = privilegedRole();
    $actor = userWith(Permission::RolesManage);
    $target = User::factory()->create();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $target, [$role->name]))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->roles)->toHaveCount(0)
        ->and(Activity::where('event', 'roles.synced')->count())->toBe(0);
});

it('assigns a privileged role with admin.assign', function () {
    $role = privilegedRole();
    $actor = userWith(Permission::RolesManage, Permission::AdminAssign);
    $target = User::factory()->create();

    app(SyncUserRoles::class)->handle($actor, $target, [$role->name]);

    expect($target->fresh()->roles->pluck('name')->all())->toBe([$role->name]);
});

it('refuses to revoke a privileged role without admin.assign', function () {
    $role = privilegedRole();
    $target = User::factory()->create();
    $target->assignRole($role);
    userWith(Permission::RolesManage);
    $actor = userWith(Permission::RolesManage);

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $target, []))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->roles->pluck('name')->all())->toBe([$role->name]);
});

it('lets roles.manage alone change ordinary roles on a user who keeps a privileged role', function () {
    $privileged = privilegedRole();
    Role::findOrCreate('Operatore', 'web');
    $target = User::factory()->create();
    $target->assignRole($privileged);
    $actor = userWith(Permission::RolesManage);

    app(SyncUserRoles::class)->handle($actor, $target, [$privileged->name, 'Operatore']);

    expect($target->fresh()->roles->pluck('name')->sort()->values()->all())->toBe(['Gestori', 'Operatore']);
});

it('refuses self-demotion from a privileged role without admin.assign', function () {
    $actor = User::factory()->create();
    $actor->assignRole(privilegedRole());

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $actor, []))
        ->toThrow(AuthorizationException::class);

    expect($actor->fresh()->roles)->toHaveCount(1);
});

it('refuses to grant roles.manage or admin.assign to a role without admin.assign', function (Permission $privileged) {
    $role = Role::findOrCreate('Operatore', 'web');
    $actor = userWith(Permission::RolesManage);

    expect(fn () => app(UpdateRolePermissions::class)->handle($actor, $role, [Permission::UsersView->value, $privileged->value]))
        ->toThrow(AuthorizationException::class);

    expect($role->fresh()->permissions)->toHaveCount(0)
        ->and(Activity::where('event', 'permissions.synced')->count())->toBe(0);
})->with([Permission::RolesManage, Permission::AdminAssign]);

it('refuses any edit of an already privileged role without admin.assign, even a no-op', function (array $names) {
    $role = privilegedRole();
    $actor = userWith(Permission::RolesManage);

    expect(fn () => app(UpdateRolePermissions::class)->handle($actor, $role, $names))
        ->toThrow(AuthorizationException::class);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe([Permission::RolesManage->value]);
})->with([
    'no-op' => [[Permission::RolesManage->value]],
    'add an ordinary permission' => [[Permission::RolesManage->value, Permission::UsersView->value]],
    'remove roles.manage' => [[]],
]);

it('allows editing a privileged role with admin.assign', function () {
    $role = privilegedRole();
    $actor = userWith(Permission::RolesManage, Permission::AdminAssign);

    app(UpdateRolePermissions::class)->handle($actor, $role, [Permission::RolesManage->value, Permission::UsersView->value]);

    expect($role->fresh()->permissions)->toHaveCount(2);
});

it('refuses to delete a privileged role without admin.assign', function () {
    $role = privilegedRole();
    $actor = userWith(Permission::RolesManage);

    expect(fn () => app(DeleteRole::class)->handle($actor, $role))->toThrow(AuthorizationException::class);

    expect(Role::where('name', 'Gestori')->exists())->toBeTrue()
        ->and(Activity::where('event', 'role.deleted')->count())->toBe(0);
});

it('deletes a privileged role with admin.assign and an ordinary one with roles.manage alone', function () {
    $privileged = privilegedRole();
    $ordinary = Role::findOrCreate('Operatore', 'web');

    app(DeleteRole::class)->handle(userWith(Permission::RolesManage, Permission::AdminAssign), $privileged);
    app(DeleteRole::class)->handle(userWith(Permission::RolesManage), $ordinary);

    expect(Role::count())->toBe(0);
});
