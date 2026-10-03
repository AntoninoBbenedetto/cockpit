<?php

use App\Enums\Permission;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('denies the audit log without audit.view', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/activities')
        ->assertForbidden();
});

it('shows the audit log with audit.view', function () {
    $this->actingAs(userWith(Permission::AuditView))
        ->get('/admin/activities')
        ->assertSuccessful();
});

it('exposes only the list page', function () {
    expect(array_keys(ActivityResource::getPages()))->toBe(['index'])
        ->and(ActivityResource::canCreate())->toBeFalse();
});

it('never allows creating, editing or deleting activities, even with every permission', function () {
    $user = userWith(...Permission::cases());
    $activity = Activity::create(['description' => 'test']);

    expect($user->can(Permission::AuditView->value))->toBeTrue()
        ->and($user->can('viewAny', Activity::class))->toBeTrue()
        ->and($user->can('view', $activity))->toBeTrue()
        ->and($user->can('create', Activity::class))->toBeFalse()
        ->and($user->can('update', $activity))->toBeFalse()
        ->and($user->can('delete', $activity))->toBeFalse()
        ->and($user->can('deleteAny', Activity::class))->toBeFalse();
});

it('lists entries readable by an audit viewer', function () {
    $viewer = userWith(Permission::AuditView);
    $this->actingAs($viewer);
    $activity = activity('rbac')->event('roles.synced')->withProperties(['old' => [], 'attributes' => ['Operatore']])->log('Ruoli utente aggiornati');

    \Pest\Livewire\livewire(ListActivities::class)
        ->assertCanSeeTableRecords([$activity])
        ->assertSee('Operatore');
});
