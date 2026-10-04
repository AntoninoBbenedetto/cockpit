<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\UpdateRolePermissions;
use App\Exceptions\LockoutException;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            app(UpdateRolePermissions::class)->handle($actor, $record, $this->data['permission_names'] ?? []);
        } catch (LockoutException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }

        $record->update($data);

        return $record;
    }
}
