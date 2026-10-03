<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\SyncUserRoles;
use App\Exceptions\LockoutException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            /** @var User $record */
            app(SyncUserRoles::class)->handle($record, $this->data['role_names'] ?? []);
        } catch (LockoutException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }

        $record->update($data);

        return $record;
    }
}
