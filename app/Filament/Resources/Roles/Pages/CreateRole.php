<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\UpdateRolePermissions;
use App\Exceptions\LockoutException;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

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

    protected function afterCreate(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();

        try {
            app(UpdateRolePermissions::class)->handle($role, $this->data['permission_names'] ?? []);
        } catch (LockoutException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
