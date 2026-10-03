<?php

use App\Enums\Permission;
use App\Models\User;

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
