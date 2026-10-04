<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

it('creates the first administrator with every permission', function () {
    $this->artisan('cockpit:create-admin', ['email' => 'admin@example.test', '--name' => 'Admin'])
        ->expectsQuestion('Password', 'a-long-test-password')
        ->assertSuccessful();

    $user = User::where('email', 'admin@example.test')->firstOrFail();

    expect($user->hasRole('Amministratore'))->toBeTrue();

    foreach (Permission::cases() as $permission) {
        expect($user->can($permission->value))->toBeTrue();
    }
});

it('refuses a password that is too short', function () {
    $this->artisan('cockpit:create-admin', ['email' => 'admin@example.test'])
        ->expectsQuestion('Password', 'short')
        ->assertFailed();

    expect(User::where('email', 'admin@example.test')->exists())->toBeFalse();
});

it('audits the administrator role grant', function () {
    $this->artisan('cockpit:create-admin', ['email' => 'admin@example.test'])
        ->expectsQuestion('Password', 'a-long-test-password')
        ->assertSuccessful();

    $user = User::where('email', 'admin@example.test')->firstOrFail();
    $entry = Activity::where('event', 'roles.synced')->firstOrFail();

    expect($entry->subject_id)->toBe($user->id)
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->properties['attributes'])->toBe(['Amministratore'])
        ->and($entry->properties['old'])->toBe([])
        ->and(Activity::all()->toJson())->not->toContain('a-long-test-password');
});

it('leaves no user behind when the audit entry cannot be written', function () {
    Activity::creating(function (Activity $activity) {
        if ($activity->event === 'roles.synced') {
            throw new RuntimeException('audit down');
        }
    });

    expect(fn () => $this->artisan('cockpit:create-admin', ['email' => 'admin@example.test'])
        ->expectsQuestion('Password', 'a-long-test-password')
        ->run())->toThrow(RuntimeException::class);

    expect(User::where('email', 'admin@example.test')->exists())->toBeFalse();
});

it('does not restore permissions removed from an existing Amministratore role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::findByName('Amministratore', 'web');
    $role->revokePermissionTo(Permission::AuditView->value);

    $this->artisan('cockpit:create-admin', ['email' => 'admin@example.test'])
        ->expectsQuestion('Password', 'a-long-test-password')
        ->assertSuccessful();

    expect($role->fresh()->hasPermissionTo(Permission::AuditView->value))->toBeFalse()
        ->and(User::where('email', 'admin@example.test')->exists())->toBeTrue();
});

it('fails cleanly on a duplicate or invalid email and leaves the role untouched', function (string $email) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::findByName('Amministratore', 'web');
    $role->revokePermissionTo(Permission::AuditView->value);
    User::factory()->create(['email' => 'taken@example.test']);
    $users = User::count();
    $activities = Activity::count();

    $this->artisan('cockpit:create-admin', ['email' => $email])
        ->expectsQuestion('Password', 'a-long-test-password')
        ->assertFailed();

    expect(User::count())->toBe($users)
        ->and(Activity::count())->toBe($activities)
        ->and($role->fresh()->hasPermissionTo(Permission::AuditView->value))->toBeFalse();
})->with(['duplicate' => ['taken@example.test'], 'invalid' => ['not-an-email']]);

it('does not seed anything when the email is rejected', function () {
    $this->artisan('cockpit:create-admin', ['email' => 'not-an-email'])
        ->expectsQuestion('Password', 'a-long-test-password')
        ->assertFailed();

    expect(Role::count())->toBe(0);
});

it('describes itself as creating an administrator, not the first one', function () {
    $command = Artisan::all()['cockpit:create-admin'];

    expect($command->getDescription())->not->toContain('primo');
});
