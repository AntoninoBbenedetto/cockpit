<?php

use App\Actions\DeleteRole;
use App\Actions\DeleteUser;
use App\Actions\SuspendUser;
use App\Actions\SyncUserRoles;
use App\Actions\UpdateRolePermissions;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Models\User;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

/**
 * Crea un unico gestore di ruoli (tramite il ruolo "Gestori") e un secondo
 * utente con altri permessi, che agisce come attore.
 */
function lastManagerSetup(): array
{
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::RolesManage->value, 'web'));

    $manager = User::factory()->create();
    $manager->assignRole($role);

    $actor = userWith(Permission::UsersSuspend, Permission::UsersDelete);

    return [$role, $manager, $actor];
}

it('blocks removing roles.manage from the role of the last manager', function () {
    [$role] = lastManagerSetup();

    expect(fn () => app(UpdateRolePermissions::class)->handle($role, []))
        ->toThrow(LockoutException::class);

    expect($role->fresh()->hasPermissionTo(Permission::RolesManage->value))->toBeTrue();
});

it('blocks deleting the role of the last manager', function () {
    [$role] = lastManagerSetup();

    expect(fn () => app(DeleteRole::class)->handle($role))->toThrow(LockoutException::class);

    expect(Role::where('name', 'Gestori')->exists())->toBeTrue();
});

it('blocks removing the role from the last manager', function () {
    [, $manager] = lastManagerSetup();

    expect(fn () => app(SyncUserRoles::class)->handle($manager, []))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->hasRole('Gestori'))->toBeTrue();
});

it('blocks suspending the last manager', function () {
    [, $manager, $actor] = lastManagerSetup();

    expect(fn () => app(SuspendUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->status)->toBe(UserStatus::Active);
});

it('blocks deleting the last manager', function () {
    [, $manager, $actor] = lastManagerSetup();

    expect(fn () => app(DeleteUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    expect(User::find($manager->id))->not->toBeNull();
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
});
