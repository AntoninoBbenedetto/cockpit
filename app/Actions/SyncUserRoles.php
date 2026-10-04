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
        $after = collect($roleNames)->sort()->values()->all();

        // Modifica e voce di audit nella stessa transazione: o entrambe o nessuna.
        LastRolesManagerGuard::protect(function () use ($actor, $target, $roleNames, $before, $after) {
            PrivilegedAccessGuard::ensureCanChangeRoles($actor, $before, $after);

            $target->syncRoles($roleNames);

            if ($after === $before) {
                return;
            }

            activity('rbac')
                ->causedBy($actor)
                ->performedOn($target)
                ->event('roles.synced')
                ->withProperties(['old' => $before, 'attributes' => $after])
                ->log('Ruoli utente aggiornati');
        });
    }
}
