<?php

use App\Enums\Permission;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Livewire\livewire;

function rolesListSnapshot(): string
{
    $html = test()->get('/admin/roles')->assertSuccessful()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    return collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $raw) => str_contains($raw, 'ListRoles'));
}

it('hides roles from a user without roles.manage', function () {
    $this->actingAs(userWith(Permission::UsersView))
        ->get('/admin/roles')
        ->assertForbidden();
});

it('shows roles to a user with roles.manage', function () {
    $this->actingAs(userWith(Permission::RolesManage))
        ->get('/admin/roles')
        ->assertSuccessful();
});

it('updates the permissions of a role through the domain action', function () {
    $role = Role::findOrCreate('Operatore', 'web');
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permission_names' => [Permission::UsersView->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()->hasPermissionTo(Permission::UsersView->value))->toBeTrue();
});

it('keeps the permissions when it would remove roles.manage from the last manager', function () {
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::RolesManage->value, 'web'));
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::AdminAssign->value, 'web'));
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $this->actingAs($manager);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permission_names' => []])
        ->call('save')
        ->assertNotified();

    expect($role->fresh()->hasPermissionTo(Permission::RolesManage->value))->toBeTrue();
});

it('creates a role on the web guard with its permissions', function () {
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(CreateRole::class)
        ->fillForm(['name' => 'Revisore', 'permission_names' => [Permission::UsersView->value]])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::findByName('Revisore', 'web');

    expect($role->guard_name)->toBe('web')
        ->and($role->hasPermissionTo(Permission::UsersView->value))->toBeTrue();
});

it('rejects a duplicate role name', function () {
    Role::findOrCreate('Operatore', 'web');
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(CreateRole::class)
        ->fillForm(['name' => 'Operatore'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

it('deletes a role through the table action', function () {
    $role = Role::findOrCreate('Operatore', 'web');
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(ListRoles::class)
        ->callAction(TestAction::make('delete')->table($role))
        ->assertNotified();

    expect(Role::where('name', 'Operatore')->exists())->toBeFalse();
});

it('shows a notification and keeps the role when deleting it would remove the last manager', function () {
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::RolesManage->value, 'web'));
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::AdminAssign->value, 'web'));
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $this->actingAs($manager);

    livewire(ListRoles::class)
        ->callAction(TestAction::make('delete')->table($role))
        ->assertNotified();

    expect(Role::where('name', 'Gestori')->exists())->toBeTrue();
});

it('rejects the roles pages without roles.manage', function () {
    $role = Role::findOrCreate('Operatore', 'web');
    $this->actingAs(userWith(Permission::UsersView, Permission::UsersDelete));

    livewire(ListRoles::class)->assertForbidden();

    $this->get("/admin/roles/{$role->getKey()}/edit")->assertForbidden();
});

it('lets only an actor who still holds roles.manage delete a role through the Livewire endpoint', function (bool $revoked, bool $exists) {
    $role = Role::findOrCreate('Operatore', 'web');
    $actor = userWith(Permission::RolesManage);

    $this->actingAs($actor);
    $snapshot = rolesListSnapshot();

    if ($revoked) {
        $actor->revokePermissionTo(Permission::RolesManage->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    foreach ([
        ['mountAction', ['delete', [], ['table' => true, 'recordKey' => (string) $role->getKey()]]],
        ['callMountedAction', []],
    ] as [$method, $params]) {
        $response = $this->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) [],
                'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
            ]],
        ], ['X-Livewire' => 'true']);

        $snapshot = $response->json('components.0.snapshot') ?? $snapshot;
    }

    expect(Role::where('name', 'Operatore')->exists())->toBe($exists);
})->with([
    'control: permission kept' => [false, false],
    'permission revoked after render' => [true, true],
]);

it('does not expose bulk delete', function () {
    $this->actingAs(userWith(Permission::RolesManage));

    expect(livewire(ListRoles::class)->instance()->getTable()->getBulkActions())->toBeEmpty();
});

it('shows a notification and keeps a privileged role when an actor without admin.assign saves it', function (array $permissions) {
    $role = privilegedRole();
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'Rinominato', 'permission_names' => $permissions])
        ->call('save')
        ->assertNotified();

    expect($role->fresh()->name)->toBe('Gestori')
        ->and($role->fresh()->permissions->pluck('name')->all())->toBe([Permission::RolesManage->value]);
})->with([
    'no-op' => [[Permission::RolesManage->value]],
    'add permission' => [[Permission::RolesManage->value, Permission::UsersView->value]],
]);

it('does not create a role with privileged permissions without admin.assign', function () {
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(CreateRole::class)
        ->fillForm(['name' => 'Furbo', 'permission_names' => [Permission::RolesManage->value]])
        ->call('create')
        ->assertNotified();

    expect(Role::where('name', 'Furbo')->exists())->toBeFalse();
});

it('creates a role with privileged permissions with admin.assign', function () {
    $this->actingAs(userWith(Permission::RolesManage, Permission::AdminAssign));

    livewire(CreateRole::class)
        ->fillForm(['name' => 'Gestori 2', 'permission_names' => [Permission::RolesManage->value]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Role::findByName('Gestori 2', 'web')->isPrivileged())->toBeTrue();
});

it('shows a notification and keeps a privileged role when deleting it without admin.assign', function () {
    $role = privilegedRole();
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(ListRoles::class)
        ->callAction(TestAction::make('delete')->table($role))
        ->assertNotified();

    expect(Role::where('name', 'Gestori')->exists())->toBeTrue();
});
