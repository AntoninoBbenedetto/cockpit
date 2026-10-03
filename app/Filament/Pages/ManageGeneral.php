<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageGeneral extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string $settings = GeneralSettings::class;

    /** @var array<string, mixed> */
    protected array $settingsBefore = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsGeneralUpdate->value) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('app_name')
                    ->label('Nome applicazione')
                    ->required()
                    ->maxLength(100),
                TextInput::make('support_email')
                    ->label('Email di supporto')
                    ->required()
                    ->email()
                    ->maxLength(255),
            ]);
    }

    protected function beforeSave(): void
    {
        $this->settingsBefore = app(GeneralSettings::class)->toArray();
    }

    protected function afterSave(): void
    {
        activity('settings')
            ->causedBy(auth()->user())
            ->event('settings.general.updated')
            ->withProperties([
                'old' => $this->settingsBefore,
                'attributes' => app(GeneralSettings::class)->toArray(),
            ])
            ->log('Impostazioni generali aggiornate');
    }
}
