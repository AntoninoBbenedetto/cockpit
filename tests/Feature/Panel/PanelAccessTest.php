<?php

use App\Enums\UserStatus;
use App\Models\User;

it('redirects guests to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets an active user reach the panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertSuccessful();
});

it('denies a suspended user', function () {
    $this->actingAs(User::factory()->create(['status' => UserStatus::Suspended]))
        ->get('/admin')
        ->assertForbidden();
});

it('denies a user suspended while their session was already open', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertSuccessful();

    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
});
