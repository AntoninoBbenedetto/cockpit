<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class SyncUserRoles
{
    /** @param  array<int, string>  $roleNames */
    public function handle(User $actor, User $target, array $roleNames): void
    {
        if (! $actor->can(Permission::RolesManage->value)) {
            throw new AuthorizationException('Non hai il permesso di assegnare ruoli.');
        }

        $before = $target->roles()->pluck('name')->sort()->values()->all();

        LastRolesManagerGuard::protect(fn () => $target->syncRoles($roleNames));

        activity('rbac')
            ->performedOn($target)
            ->event('roles.synced')
            ->withProperties(['old' => $before, 'attributes' => collect($roleNames)->sort()->values()->all()])
            ->log('Ruoli utente aggiornati');
    }
}
