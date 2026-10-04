<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\DeleteUser;
use App\Actions\ReactivateUser;
use App\Actions\SuspendUser;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (UserStatus $state) => $state === UserStatus::Active ? 'Attivo' : 'Sospeso')
                    ->color(fn (UserStatus $state) => $state === UserStatus::Active ? 'success' : 'danger'),
                TextColumn::make('roles.name')
                    ->label('Ruoli')
                    ->badge(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('suspend')
                    ->label('Sospendi')
                    ->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()
                    ->authorize('suspend')
                    ->visible(fn (User $record) => $record->status === UserStatus::Active)
                    ->action(function (User $record) {
                        self::run(
                            fn (User $actor) => app(SuspendUser::class)->handle($actor, $record),
                            'Utente sospeso',
                        );
                    }),
                Action::make('reactivate')
                    ->label('Riattiva')
                    ->icon('heroicon-o-check-circle')
                    ->authorize('suspend')
                    ->visible(fn (User $record) => $record->status === UserStatus::Suspended)
                    ->action(function (User $record) {
                        self::run(
                            fn () => app(ReactivateUser::class)->handle($record),
                            'Utente riattivato',
                        );
                    }),
                Action::make('delete')
                    ->label('Elimina')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('delete')
                    ->action(function (User $record) {
                        self::run(
                            fn (User $actor) => app(DeleteUser::class)->handle($actor, $record),
                            'Utente eliminato',
                        );
                    }),
            ]);
    }

    /** @param  callable(User): mixed  $operation */
    private static function run(callable $operation, string $successTitle): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $operation($actor);
        } catch (DomainException|LockoutException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($successTitle)->send();
    }
}
