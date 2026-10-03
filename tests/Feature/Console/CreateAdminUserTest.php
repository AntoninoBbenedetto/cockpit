<?php

use App\Enums\Permission;
use App\Models\User;
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
