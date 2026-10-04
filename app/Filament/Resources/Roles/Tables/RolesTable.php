<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Actions\DeleteRole;
use App\Exceptions\LockoutException;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

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
                        /** @var User $actor */
                        $actor = auth()->user();

                        try {
                            app(DeleteRole::class)->handle($actor, $record);
                        } catch (AuthorizationException|DomainException|LockoutException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Ruolo eliminato')->send();
                    }),
            ]);
    }
}
