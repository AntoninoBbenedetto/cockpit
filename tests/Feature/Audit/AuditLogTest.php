<?php

use App\Actions\DeleteRole;
use App\Actions\DeleteUser;
use App\Actions\ReactivateUser;
use App\Actions\SuspendUser;
use App\Actions\SyncUserRoles;
use App\Actions\UpdateRolePermissions;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Filament\Pages\ManageGeneral;
use App\Models\Role;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

it('logs user creation without the password', function () {
    $actor = userWith(Permission::UsersCreate);
    $this->actingAs($actor);

    $user = User::factory()->create(['password' => 'secret-password-123']);

    $json = Activity::all()->toJson();

    expect(Activity::where('subject_id', $user->id)->where('subject_type', $user->getMorphClass())->exists())->toBeTrue()
        ->and($json)->not->toContain('secret-password-123')
        ->and($json)->not->toContain($user->fresh()->password)
        ->and($json)->not->toContain('"password"')
        ->and($json)->not->toContain('remember_token');
});

it('logs user updates without the password or its hash', function () {
    $user = User::factory()->create();
    $user->update(['name' => 'Nuovo Nome', 'password' => 'another-secret-456']);

    $json = Activity::all()->toJson();

    expect($json)->toContain('Nuovo Nome')
        ->and($json)->not->toContain('another-secret-456')
        ->and($json)->not->toContain('"password"')
        ->and($json)->not->toContain($user->fresh()->password)
        ->and($json)->not->toContain('remember_token');
});

it('logs a password change as a value-less entry', function () {
    $actor = userWith(Permission::UsersUpdate);
    $this->actingAs($actor);
    $user = User::factory()->create();
    Activity::query()->delete();

    $user->update(['password' => 'another-secret-789']);

    $entries = Activity::all();
    $entry = $entries->first();

    expect($entries)->toHaveCount(1)
        ->and($entry->event)->toBe('password.changed')
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->causer_id)->toBe($actor->id)
        ->and($entry->attribute_changes)->toBeEmpty()
        ->and($entry->properties)->toBeEmpty()
        ->and($entries->toJson())->not->toContain('another-secret-789')
        ->and($entries->toJson())->not->toContain($user->fresh()->password)
        ->and($entries->toJson())->not->toContain('"password":');
});

it('does not log remember_token-only changes', function () {
    $user = User::factory()->create();
    Activity::query()->delete();

    $user->forceFill(['remember_token' => 'tok-abc-123'])->save();

    expect(Activity::count())->toBe(0);
});

it('writes no password.changed on a name-only update or on creation', function () {
    $user = User::factory()->create(['password' => 'initial-secret-12345']);
    $user->update(['name' => 'Altro Nome']);

    expect(Activity::where('event', 'password.changed')->count())->toBe(0);
});

it('writes no roles.synced when the role set is unchanged', function () {
    Role::findOrCreate('Operatore', 'web');
    $actor = userWith(Permission::RolesManage);
    $target = User::factory()->create();

    app(SyncUserRoles::class)->handle($actor, $target, []);
    expect(Activity::where('event', 'roles.synced')->count())->toBe(0);

    app(SyncUserRoles::class)->handle($actor, $target, ['Operatore']);
    app(SyncUserRoles::class)->handle($actor, $target, ['Operatore']);

    $entries = Activity::where('event', 'roles.synced')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->properties['old'])->toBe([])
        ->and($entries->first()->properties['attributes'])->toBe(['Operatore']);
});

it('writes no permissions.synced when the permissions are unchanged', function () {
    $role = Role::findOrCreate('Operatore', 'web');
    $actor = User::factory()->create();

    app(UpdateRolePermissions::class)->handle($actor, $role, []);
    expect(Activity::where('event', 'permissions.synced')->count())->toBe(0);

    app(UpdateRolePermissions::class)->handle($actor, $role, [Permission::UsersView->value]);
    app(UpdateRolePermissions::class)->handle($actor, $role, [Permission::UsersView->value]);

    expect(Activity::where('event', 'permissions.synced')->count())->toBe(1);
});

it('logs status changes with old and new values', function () {
    $user = User::factory()->create();
    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $activity = Activity::where('event', 'updated')->where('subject_id', $user->id)->latest('id')->firstOrFail();

    expect($activity->attribute_changes['attributes']['status'])->toBe('suspended')
        ->and($activity->attribute_changes['old']['status'])->toBe('active');
});

it('logs role creation and deletion', function () {
    $role = Role::create(['name' => 'Operatore', 'guard_name' => 'web']);
    $role->delete();

    expect(Activity::where('subject_type', (new Role)->getMorphClass())->where('event', 'created')->count())->toBe(1)
        ->and(Activity::where('subject_type', (new Role)->getMorphClass())->where('event', 'deleted')->count())->toBe(1);
});

it('logs suspend, reactivate and delete user actions with the authenticated causer', function () {
    $actor = userWith(Permission::UsersSuspend, Permission::UsersDelete);
    $this->actingAs($actor);
    $target = User::factory()->create();
    $targetId = $target->id;

    app(SuspendUser::class)->handle($actor, $target);
    $suspended = Activity::where('subject_id', $targetId)->where('event', 'updated')->latest('id')->firstOrFail();

    app(ReactivateUser::class)->handle($target);
    $reactivated = Activity::where('subject_id', $targetId)->where('event', 'updated')->latest('id')->firstOrFail();

    app(DeleteUser::class)->handle($actor, $target);
    $deleted = Activity::where('subject_id', $targetId)->where('event', 'deleted')->firstOrFail();

    expect($suspended->attribute_changes['attributes']['status'])->toBe('suspended')
        ->and($suspended->causer_id)->toBe($actor->id)
        ->and($reactivated->attribute_changes['old']['status'])->toBe('suspended')
        ->and($reactivated->attribute_changes['attributes']['status'])->toBe('active')
        ->and($reactivated->causer_id)->toBe($actor->id)
        ->and($deleted->causer_id)->toBe($actor->id)
        ->and($deleted->subject_type)->toBe($target->getMorphClass())
        ->and(Activity::all()->toJson())->not->toContain('"password"');
});

it('logs a role assignment with old and new values and the causer', function () {
    Role::findOrCreate('Operatore', 'web');
    $actor = userWith(Permission::RolesManage);
    $this->actingAs($actor);
    $target = User::factory()->create();

    app(SyncUserRoles::class)->handle($actor, $target, ['Operatore']);

    $activity = Activity::where('event', 'roles.synced')->firstOrFail();

    expect($activity->properties['attributes'])->toBe(['Operatore'])
        ->and($activity->properties['old'])->toBe([])
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->subject_id)->toBe($target->id);
});

it('logs permission changes and role deletion with the causer', function () {
    $actor = userWith(Permission::RolesManage);
    $this->actingAs($actor);
    $role = Role::findOrCreate('Operatore', 'web');

    app(UpdateRolePermissions::class)->handle($actor, $role, [Permission::UsersView->value]);
    app(DeleteRole::class)->handle($actor, $role);

    $perm = Activity::where('event', 'permissions.synced')->firstOrFail();
    $del = Activity::where('event', 'role.deleted')->firstOrFail();

    expect($perm->properties['attributes'])->toBe([Permission::UsersView->value])
        ->and($perm->causer_id)->toBe($actor->id)
        ->and($del->properties['name'])->toBe('Operatore')
        ->and($del->causer_id)->toBe($actor->id);
});

it('writes no rbac entries when the lockout guard rolls the change back', function () {
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate(Permission::RolesManage->value, 'web'));
    $role->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate(Permission::AdminAssign->value, 'web'));
    $manager = User::factory()->create();
    $manager->assignRole($role);
    $admin = userWith(Permission::AdminAssign);
    Activity::query()->delete();

    expect(fn () => app(UpdateRolePermissions::class)->handle($admin, $role, []))->toThrow(LockoutException::class)
        ->and(fn () => app(DeleteRole::class)->handle($admin, $role))->toThrow(LockoutException::class)
        ->and(fn () => app(SyncUserRoles::class)->handle($manager, $manager, []))->toThrow(LockoutException::class);

    expect(Activity::count())->toBe(0);
});

it('rolls back the rbac change when its audit entry cannot be written', function () {
    $actor = userWith(Permission::RolesManage);
    $role = Role::findOrCreate('Operatore', 'web');
    $role->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate(Permission::UsersView->value, 'web'));
    $target = User::factory()->create();
    $target->assignRole($role);

    Activity::creating(fn () => throw new RuntimeException('audit down'));

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $target, []))->toThrow(RuntimeException::class)
        ->and($target->fresh()->hasRole('Operatore'))->toBeTrue();

    expect(fn () => app(UpdateRolePermissions::class)->handle($actor, $role, []))->toThrow(RuntimeException::class)
        ->and($role->fresh()->hasPermissionTo(Permission::UsersView->value))->toBeTrue();

    expect(fn () => app(DeleteRole::class)->handle($actor, $role))->toThrow(RuntimeException::class)
        ->and(Role::where('name', 'Operatore')->exists())->toBeTrue();
});

it('logs a settings change with old and new values and the causer', function () {
    $actor = userWith(Permission::SettingsGeneralUpdate);
    $this->actingAs($actor);

    livewire(ManageGeneral::class)
        ->fillForm(['app_name' => 'Nuovo nome', 'support_email' => 'a@example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::where('event', 'settings.general.updated')->firstOrFail();

    expect($activity->properties['old']['app_name'])->toBe('Cockpit')
        ->and($activity->properties['attributes']['app_name'])->toBe('Nuovo nome')
        ->and($activity->properties['attributes']['support_email'])->toBe('a@example.test')
        ->and($activity->causer_id)->toBe($actor->id);
});

it('does not log a settings entry when validation fails', function () {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate));

    livewire(ManageGeneral::class)
        ->fillForm(['app_name' => '', 'support_email' => 'not-an-email'])
        ->call('save')
        ->assertHasFormErrors();

    expect(Activity::where('event', 'settings.general.updated')->count())->toBe(0);
});

it('records only the explicit settings keys', function () {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate));

    livewire(ManageGeneral::class)
        ->fillForm(['app_name' => 'X', 'support_email' => 'a@example.test'])
        ->call('save');

    $activity = Activity::where('event', 'settings.general.updated')->firstOrFail();

    expect(array_keys($activity->properties['attributes']))->toBe(['app_name', 'support_email'])
        ->and(array_keys($activity->properties['old']))->toBe(['app_name', 'support_email']);
});

it('logs a role rename on the role log with old and new name', function () {
    $role = Role::create(['name' => 'Operatore', 'guard_name' => 'web']);

    $role->update(['name' => 'Supporto']);

    $entry = Activity::where('log_name', 'role')->where('event', 'updated')->firstOrFail();

    expect($entry->subject_id)->toBe($role->id)
        ->and($entry->attribute_changes['attributes']['name'])->toBe('Supporto')
        ->and($entry->attribute_changes['old']['name'])->toBe('Operatore');
});
