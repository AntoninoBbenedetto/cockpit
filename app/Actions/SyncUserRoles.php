<?php

namespace App\Actions;

use App\Models\User;

class SyncUserRoles
{
    /** @param  array<int, string>  $roleNames */
    public function handle(User $target, array $roleNames): void
    {
        $before = $target->roles()->pluck('name')->sort()->values()->all();

        LastRolesManagerGuard::protect(fn () => $target->syncRoles($roleNames));

        activity('rbac')
            ->performedOn($target)
            ->event('roles.synced')
            ->withProperties(['old' => $before, 'attributes' => collect($roleNames)->sort()->values()->all()])
            ->log('Ruoli utente aggiornati');
    }
}
