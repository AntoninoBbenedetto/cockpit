<?php

use App\Enums\Permission;
use App\Filament\Pages\ManageGeneral;
use App\Models\User;
use App\Settings\GeneralSettings;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Livewire\livewire;

it('has typed defaults', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->app_name)->toBe('Cockpit')
        ->and($settings->support_email)->toBe('support@example.test');
});

it('denies the settings page to guests', function () {
    $this->get('/admin/manage-general')->assertRedirect();
});

it('denies the settings page without settings.general.update', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/manage-general')
        ->assertForbidden();
});

it('shows the settings page with settings.general.update', function () {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate))
        ->get('/admin/manage-general')
        ->assertSuccessful();
});

it('saves valid settings', function () {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate));

    livewire(ManageGeneral::class)
        ->fillForm(['app_name' => 'Nuovo nome', 'support_email' => 'aiuto@example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = app(GeneralSettings::class)->refresh();

    expect($stored->app_name)->toBe('Nuovo nome')
        ->and($stored->support_email)->toBe('aiuto@example.test');
});

it('rejects invalid values and keeps the old ones', function (array $data, string $field) {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate));

    livewire(ManageGeneral::class)
        ->fillForm($data)
        ->call('save')
        ->assertHasFormErrors([$field]);

    $stored = app(GeneralSettings::class)->refresh();

    expect($stored->app_name)->toBe('Cockpit')
        ->and($stored->support_email)->toBe('support@example.test');
})->with([
    'empty name' => [['app_name' => '', 'support_email' => 'a@example.test'], 'app_name'],
    'name too long' => [['app_name' => str_repeat('x', 101), 'support_email' => 'a@example.test'], 'app_name'],
    'empty email' => [['app_name' => 'Cockpit', 'support_email' => ''], 'support_email'],
    'invalid email' => [['app_name' => 'Cockpit', 'support_email' => 'not-an-email'], 'support_email'],
    'email too long' => [['app_name' => 'Cockpit', 'support_email' => str_repeat('a', 250).'@example.test'], 'support_email'],
]);

it('does not save through a forged Livewire call after the permission is revoked', function (bool $revoke, string $expected) {
    $user = userWith(Permission::SettingsGeneralUpdate);
    $this->actingAs($user);

    $html = $this->get('/admin/manage-general')->assertSuccessful()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $raw) => str_contains($raw, 'ManageGeneral'));

    if ($revoke) {
        $user->revokePermissionTo(Permission::SettingsGeneralUpdate->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    $this->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.app_name' => 'Forgiato', 'data.support_email' => 'forged@example.test'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true']);

    expect(app(GeneralSettings::class)->refresh()->app_name)->toBe($expected);
})->with([
    'control: permission kept' => [false, 'Forgiato'],
    'permission revoked after render' => [true, 'Cockpit'],
]);
