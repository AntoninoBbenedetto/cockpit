<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Permission;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(12)
                    ->dehydrated(fn (?string $state) => filled($state)),
                Select::make('role_names')
                    ->label('Ruoli')
                    ->multiple()
                    ->visible(fn () => auth()->user()?->can(Permission::RolesManage->value) ?? false)
                    ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name'))
                    ->afterStateHydrated(fn (Select $component, ?User $record) => $component->state(
                        $record?->roles->pluck('name')->all() ?? []
                    ))
                    ->dehydrated(false),
            ]);
    }
}
