<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\SyncUserRoles;
use App\Enums\Permission;
use App\Exceptions\LockoutException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        // Il campo del form è stato manipolabile: l'autorità è solo il permesso.
        if (! $actor->can(Permission::RolesManage->value)) {
            return;
        }

        /** @var User $user */
        $user = $this->getRecord();

        try {
            app(SyncUserRoles::class)->handle($actor, $user, $this->data['role_names'] ?? []);
        } catch (LockoutException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
