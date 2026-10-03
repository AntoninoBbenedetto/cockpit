<?php

namespace App\Filament\Resources\Activities\Tables;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Autore')
                    ->state(fn (Activity $record): ?string => self::causerLabel($record))
                    ->placeholder('Sistema'),
                TextColumn::make('log_name')
                    ->label('Registro')
                    ->badge(),
                TextColumn::make('event')
                    ->label('Evento'),
                TextColumn::make('description')
                    ->label('Descrizione')
                    ->wrap(),
                TextColumn::make('subject_type')
                    ->label('Soggetto')
                    ->formatStateUsing(fn (?string $state) => $state === null ? null : class_basename($state)),
                TextColumn::make('subject_id')
                    ->label('ID soggetto'),
                TextColumn::make('changes')
                    ->label('Modifiche')
                    ->state(fn (Activity $record): string => self::changes($record))
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function causerLabel(Activity $record): ?string
    {
        $causer = $record->causer;

        if ($causer instanceof User) {
            return $causer->name;
        }

        return $record->causer_id ? "Utente eliminato #{$record->causer_id}" : null;
    }

    private static function changes(Activity $record): string
    {
        $data = array_filter([
            'modifiche' => $record->attribute_changes?->toArray(),
            'proprieta' => $record->properties?->toArray(),
        ]);

        return $data === [] ? '' : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
