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
use Spatie\Permission\Models\Permission as PermissionModel;

/**
 * Crea un unico gestore di ruoli (tramite il ruolo "Gestori") e un secondo
 * utente con altri permessi, che agisce come attore.
 */
function lastManagerSetup(): array
{
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::RolesManage->value, 'web'));
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::AdminAssign->value, 'web'));

    $manager = User::factory()->create();
    $manager->assignRole($role);

    $actor = userWith(Permission::UsersSuspend, Permission::UsersDelete);

    return [$role, $manager, $actor];
}

it('blocks removing roles.manage from the role of the last manager', function () {
    [$role, $manager] = lastManagerSetup();

    expect(fn () => app(UpdateRolePermissions::class)->handle(userWith(Permission::AdminAssign), $role, []))
        ->toThrow(LockoutException::class);

    expect($role->fresh()->hasPermissionTo(Permission::RolesManage->value))->toBeTrue();
    expect($manager->fresh()->can(Permission::RolesManage->value))->toBeTrue();
});

it('blocks deleting the role of the last manager', function () {
    [$role, $manager] = lastManagerSetup();

    expect(fn () => app(DeleteRole::class)->handle(userWith(Permission::AdminAssign), $role))->toThrow(LockoutException::class);

    expect(Role::where('name', 'Gestori')->exists())->toBeTrue();
    expect($manager->fresh()->can(Permission::RolesManage->value))->toBeTrue();
});

it('blocks removing the role from the last manager', function () {
    [, $manager] = lastManagerSetup();

    expect(fn () => app(SyncUserRoles::class)->handle($manager, $manager, []))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->hasRole('Gestori'))->toBeTrue();
    expect($manager->fresh()->can(Permission::RolesManage->value))->toBeTrue();
});

it('blocks suspending the last manager', function () {
    [, $manager, $actor] = lastManagerSetup();

    expect(fn () => app(SuspendUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->status)->toBe(UserStatus::Active);
    expect($manager->fresh()->can(Permission::RolesManage->value))->toBeTrue();
});

it('blocks deleting the last manager', function () {
    [, $manager, $actor] = lastManagerSetup();

    expect(fn () => app(DeleteUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    // $manager->exists è false dopo il delete rolled back: si ricarica dal database.
    expect(User::find($manager->id))->not->toBeNull()
        ->and(User::find($manager->id)->can(Permission::RolesManage->value))->toBeTrue();
});

it('allows the same changes when another manager remains', function () {
    [$role, $manager, $actor] = lastManagerSetup();
    $other = User::factory()->create();
    $other->assignRole($role);

    app(SuspendUser::class)->handle($actor, $manager);

    expect($manager->fresh()->status)->toBe(UserStatus::Suspended);
});

it('does not count suspended users as managers', function () {
    [$role, $manager, $actor] = lastManagerSetup();
    $suspended = User::factory()->create(['status' => UserStatus::Suspended]);
    $suspended->assignRole($role);

    expect(fn () => app(SuspendUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->status)->toBe(UserStatus::Active);
});

it('allows deleting the role when another manager remains', function () {
    [$role] = lastManagerSetup();
    $other = userWith(Permission::RolesManage);

    app(DeleteRole::class)->handle(userWith(Permission::AdminAssign), $role);

    expect(Role::where('name', 'Gestori')->exists())->toBeFalse()
        ->and($other->fresh()->can(Permission::RolesManage->value))->toBeTrue();
});

it('allows updating the role permissions when another manager remains', function () {
    [$role] = lastManagerSetup();
    userWith(Permission::RolesManage);

    app(UpdateRolePermissions::class)->handle(userWith(Permission::AdminAssign), $role, []);

    expect($role->fresh()->permissions)->toHaveCount(0);
});

it('allows removing the role from a manager when another manager remains', function () {
    [, $manager] = lastManagerSetup();
    // Il setup rende il gestore anche titolare di admin.assign: l'altro gestore deve avere entrambi.
    userWith(Permission::RolesManage, Permission::AdminAssign);

    app(SyncUserRoles::class)->handle($manager, $manager, []);

    expect($manager->fresh()->hasRole('Gestori'))->toBeFalse();
});

it('allows deleting a manager user when another manager remains', function () {
    [, $manager, $actor] = lastManagerSetup();
    // Il setup rende il gestore anche titolare di admin.assign: l'altro gestore deve avere entrambi.
    userWith(Permission::RolesManage, Permission::AdminAssign);

    app(DeleteUser::class)->handle($actor, $manager);

    expect(User::find($manager->id))->toBeNull();
});

it('blocks suspending a last manager who holds roles.manage directly', function () {
    $manager = userWith(Permission::RolesManage);
    $actor = userWith(Permission::UsersSuspend);

    expect(fn () => app(SuspendUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->status)->toBe(UserStatus::Active)
        ->and($manager->fresh()->can(Permission::RolesManage->value))->toBeTrue();
});
