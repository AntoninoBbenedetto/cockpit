<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\PrivilegedAccessGuard;
use App\Actions\UpdateRolePermissions;
use App\Exceptions\LockoutException;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    /**
     * Verifica prima della creazione, così un rifiuto non lascia un ruolo orfano.
     */
    protected function beforeCreate(): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            PrivilegedAccessGuard::ensureCanChangePermissions($actor, [], $this->data['permission_names'] ?? []);
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();

        /** @var User $actor */
        $actor = auth()->user();

        try {
            app(UpdateRolePermissions::class)->handle($actor, $role, $this->data['permission_names'] ?? []);
        } catch (LockoutException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
