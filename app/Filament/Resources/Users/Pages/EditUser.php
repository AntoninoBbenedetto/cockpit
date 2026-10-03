<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\SyncUserRoles;
use App\Enums\Permission;
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
        /** @var User $record */

        /** @var User $actor */
        $actor = auth()->user();

        if ($actor->can(Permission::RolesManage->value)) {
            $requested = collect($this->data['role_names'] ?? [])->sort()->values()->all();
            $current = $record->roles()->pluck('name')->sort()->values()->all();

            if ($requested !== $current) {
                try {
                    app(SyncUserRoles::class)->handle($actor, $record, $requested);
                } catch (LockoutException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $this->halt();
                }
            }
        }

        $record->update($data);

        return $record;
    }
}
