<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

it('hides the users list from a user without users.view', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/users')
        ->assertForbidden();
});

it('shows the users list to a user with users.view', function () {
    $this->actingAs(userWith(Permission::UsersView))
        ->get('/admin/users')
        ->assertSuccessful();
});

it('lets a user with users.suspend suspend another user from the table', function () {
    $target = User::factory()->create();

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersSuspend));

    livewire(ListUsers::class)
        ->callAction(TestAction::make('suspend')->table($target))
        ->assertNotified();

    expect($target->fresh()->status)->toBe(UserStatus::Suspended);
});

it('lets a user with users.suspend reactivate a suspended user', function () {
    $target = User::factory()->create();
    $target->forceFill(['status' => UserStatus::Suspended])->save();

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersSuspend));

    livewire(ListUsers::class)
        ->callAction(TestAction::make('reactivate')->table($target))
        ->assertNotified();

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('rejects a direct suspend call without users.suspend', function () {
    $target = User::factory()->create();

    $this->actingAs(userWith(Permission::UsersView));

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('suspend')->table($target))
        ->mountAction(TestAction::make('suspend')->table($target))
        ->call('callMountedAction');

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('rejects a direct delete call without users.delete', function () {
    $target = User::factory()->create();

    $this->actingAs(userWith(Permission::UsersView));

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('delete')->table($target))
        ->mountAction(TestAction::make('delete')->table($target))
        ->call('callMountedAction');

    expect(User::find($target->id))->not->toBeNull();
});

it('deletes a user through the table action with users.delete', function () {
    $target = User::factory()->create();

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersDelete));

    livewire(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($target))
        ->assertNotified();

    expect(User::find($target->id))->toBeNull();
});

it('does not offer suspend or delete on the own row', function () {
    $actor = userWith(Permission::UsersView, Permission::UsersSuspend, Permission::UsersDelete);

    $this->actingAs($actor);

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('suspend')->table($actor))
        ->assertActionHidden(TestAction::make('delete')->table($actor))
        ->mountAction(TestAction::make('suspend')->table($actor))
        ->call('callMountedAction')
        ->mountAction(TestAction::make('delete')->table($actor))
        ->call('callMountedAction');

    expect($actor->fresh()->status)->toBe(UserStatus::Active)
        ->and(User::find($actor->id))->not->toBeNull();
});

/** Renders the page over HTTP and returns the ListUsers component snapshot. */
function listUsersSnapshot(): string
{
    $html = test()->get('/admin/users')->assertSuccessful()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    return collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $raw) => str_contains($raw, 'ListUsers'));
}

/**
 * Sends calls to the real HTTP Livewire endpoint (panel middleware included),
 * which the livewire() test harness bypasses.
 *
 * @param  array<int, array{0: string, 1: array<int, mixed>}>  $calls
 */
function sendLivewireCalls(string $snapshot, array $calls): void
{
    foreach ($calls as [$method, $params]) {
        $response = test()->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) [],
                'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
            ]],
        ], ['X-Livewire' => 'true']);

        $snapshot = $response->json('components.0.snapshot') ?? $snapshot;
    }
}

it('lets only an actor who is still active act through the Livewire endpoint', function (bool $suspendedAfterRender, UserStatus $expected) {
    $actor = userWith(Permission::UsersView, Permission::UsersSuspend);
    $target = User::factory()->create();

    $this->actingAs($actor);
    $snapshot = listUsersSnapshot();

    if ($suspendedAfterRender) {
        $actor->forceFill(['status' => UserStatus::Suspended])->save();
    }

    sendLivewireCalls($snapshot, [
        ['mountAction', ['suspend', [], ['table' => true, 'recordKey' => (string) $target->getKey()]]],
        ['callMountedAction', []],
    ]);

    expect($target->fresh()->status)->toBe($expected);
})->with([
    'control: actor still active' => [false, UserStatus::Suspended],
    'actor suspended after the page was rendered' => [true, UserStatus::Active],
]);

it('does not expose bulk delete', function () {
    $this->actingAs(userWith(Permission::UsersView, Permission::UsersDelete));

    $table = livewire(ListUsers::class)->instance()->getTable();

    expect($table->getBulkActions())->toBeEmpty();
});

it('does not update a user without users.update', function () {
    $target = User::factory()->create(['name' => 'Originale']);

    $this->actingAs(userWith(Permission::UsersView))
        ->get("/admin/users/{$target->id}/edit")
        ->assertForbidden();
});

it('has no status field in the form', function () {
    $target = User::factory()->create();

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersUpdate));

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->assertFormFieldDoesNotExist('status');
});

it('assigns roles through the domain action', function () {
    Role::findOrCreate('Operatore', 'web');
    $target = User::factory()->create();

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersUpdate));

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['role_names' => ['Operatore']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole('Operatore'))->toBeTrue();
});

it('keeps the password when left empty on edit and hashes a new one once', function () {
    $target = User::factory()->create();
    $oldHash = $target->password;

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersUpdate));

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['name' => 'Nuovo Nome', 'password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->name)->toBe('Nuovo Nome')
        ->and($target->fresh()->password)->toBe($oldHash);

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['password' => 'una-password-lunga-12'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('una-password-lunga-12', $target->fresh()->password))->toBeTrue();
});

it('creates a user with roles and a password of at least 12 characters', function () {
    Role::findOrCreate('Operatore', 'web');

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersCreate));

    livewire(CreateUser::class)
        ->fillForm(['name' => 'Mario', 'email' => 'mario@example.com', 'password' => 'corta'])
        ->call('create')
        ->assertHasFormErrors(['password' => 'min']);

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Mario',
            'email' => 'mario@example.com',
            'password' => 'una-password-lunga-12',
            'role_names' => ['Operatore'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'mario@example.com')->firstOrFail();

    expect($created->status)->toBe(UserStatus::Active)
        ->and($created->hasRole('Operatore'))->toBeTrue();
});

it('shows a notification and keeps the roles when it would remove the last roles manager', function () {
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::RolesManage->value, 'web'));
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $this->actingAs(userWith(Permission::UsersView, Permission::UsersUpdate));

    livewire(EditUser::class, ['record' => $manager->getKey()])
        ->fillForm(['role_names' => []])
        ->call('save')
        ->assertNotified();

    expect($manager->fresh()->hasRole('Gestori'))->toBeTrue();
});
