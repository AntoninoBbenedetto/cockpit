<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\Permission;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                CheckboxList::make('permission_names')
                    ->label('Permessi')
                    ->options(collect(Permission::cases())->mapWithKeys(fn (Permission $p) => [$p->value => $p->value])->all())
                    ->afterStateHydrated(fn (CheckboxList $component, ?Role $record) => $component->state(
                        $record?->permissions->pluck('name')->all() ?? []
                    ))
                    ->dehydrated(false),
            ]);
    }
}
