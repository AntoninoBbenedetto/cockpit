<?php

use App\Actions\DeleteRole;
use App\Actions\DeleteUser;
use App\Actions\SuspendUser;
use App\Actions\SyncUserRoles;
use App\Actions\UpdateRolePermissions;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Models\Role;
use App\Models\User;

/**
 * Un unico titolare di admin.assign (ruolo "Assegnatori", solo admin.assign) e
 * un gestore con solo roles.manage: il livello roles.manage non è mai a rischio,
 * quindi può scattare soltanto la protezione di admin.assign.
 *
 * L'actor è sospeso: conserva i permessi (può agire sulle Action) ma non conta
 * come titolare attivo di admin.assign.
 */
function lastAssignerSetup(): array
{
    $role = privilegedRole('Assegnatori', Permission::AdminAssign);

    $assigner = User::factory()->create();
    $assigner->assignRole($role);

    userWith(Permission::RolesManage);

    $actor = userWith(Permission::AdminAssign, Permission::RolesManage, Permission::UsersSuspend, Permission::UsersDelete);
    $actor->forceFill(['status' => UserStatus::Suspended])->save();

    return [$role, $assigner, $actor];
}

it('blocks removing admin.assign from the role of the last assigner', function () {
    [$role, $assigner, $actor] = lastAssignerSetup();

    expect(fn () => app(UpdateRolePermissions::class)->handle($actor, $role, []))
        ->toThrow(LockoutException::class, 'admin.assign');

    expect($assigner->fresh()->can(Permission::AdminAssign->value))->toBeTrue();
});

it('blocks deleting the role of the last assigner', function () {
    [$role, $assigner, $actor] = lastAssignerSetup();

    expect(fn () => app(DeleteRole::class)->handle($actor, $role))
        ->toThrow(LockoutException::class, 'admin.assign');

    expect(Role::where('name', 'Assegnatori')->exists())->toBeTrue()
        ->and($assigner->fresh()->can(Permission::AdminAssign->value))->toBeTrue();
});

it('blocks removing the role from the last assigner', function () {
    [, $assigner, $actor] = lastAssignerSetup();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $assigner, []))
        ->toThrow(LockoutException::class, 'admin.assign');

    expect($assigner->fresh()->hasRole('Assegnatori'))->toBeTrue();
});

it('blocks suspending the last assigner', function () {
    [, $assigner, $actor] = lastAssignerSetup();

    expect(fn () => app(SuspendUser::class)->handle($actor, $assigner))
        ->toThrow(LockoutException::class, 'admin.assign');

    expect($assigner->fresh()->status)->toBe(UserStatus::Active);
});

it('blocks deleting the last assigner', function () {
    [, $assigner, $actor] = lastAssignerSetup();

    expect(fn () => app(DeleteUser::class)->handle($actor, $assigner))
        ->toThrow(LockoutException::class, 'admin.assign');

    expect(User::find($assigner->id))->not->toBeNull();
});

it('allows the same changes when another active assigner remains', function () {
    [$role, $assigner, $actor] = lastAssignerSetup();
    $other = User::factory()->create();
    $other->assignRole($role);

    app(SuspendUser::class)->handle($actor, $assigner);
    app(SyncUserRoles::class)->handle($actor, $assigner, []);
    app(DeleteUser::class)->handle($actor, $assigner);

    expect(User::find($assigner->id))->toBeNull()
        ->and($other->fresh()->can(Permission::AdminAssign->value))->toBeTrue();
});

it('allows removing admin.assign from a role when another active assigner holds it directly', function () {
    [$role, , $actor] = lastAssignerSetup();
    userWith(Permission::AdminAssign);

    app(UpdateRolePermissions::class)->handle($actor, $role, []);

    expect($role->fresh()->permissions)->toHaveCount(0);
});

it('does not count suspended users as assigners', function () {
    [$role, $assigner, $actor] = lastAssignerSetup();
    $suspended = User::factory()->create(['status' => UserStatus::Suspended]);
    $suspended->assignRole($role);

    expect(fn () => app(SuspendUser::class)->handle($actor, $assigner))
        ->toThrow(LockoutException::class, 'admin.assign');

    expect($assigner->fresh()->status)->toBe(UserStatus::Active);
});

it('does not block anything when nobody holds admin.assign', function () {
    $manager = userWith(Permission::RolesManage);
    $other = userWith(Permission::RolesManage);
    $actor = userWith(Permission::UsersSuspend);

    app(SuspendUser::class)->handle($actor, $manager);

    expect($manager->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($other->fresh()->status)->toBe(UserStatus::Active);
});
