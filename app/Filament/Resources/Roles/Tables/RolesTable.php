<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Actions\DeleteRole;
use App\Exceptions\LockoutException;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('permissions.name')
                    ->label('Permessi')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('delete')
                    ->label('Elimina')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('delete')
                    ->action(function (Role $record) {
                        try {
                            app(DeleteRole::class)->handle($record);
                        } catch (DomainException|LockoutException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Ruolo eliminato')->send();
                    }),
            ]);
    }
}
