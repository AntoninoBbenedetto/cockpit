<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function view(User $user, User $target): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersCreate->value);
    }

    public function update(User $user, User $target): bool
    {
        return $user->can(Permission::UsersUpdate->value) && $this->outranks($user, $target);
    }

    public function suspend(User $user, User $target): bool
    {
        return $user->can(Permission::UsersSuspend->value) && $user->isNot($target) && $this->outranks($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can(Permission::UsersDelete->value) && $user->isNot($target) && $this->outranks($user, $target);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Nessun privilege-up: admin.assign (amministrazione completa) agisce su
     * chiunque; chi non lo ha non tocca mai un utente con un ruolo privilegiato
     * e, per gli altri, solo chi ha permessi già in suo possesso (ADR-004).
     */
    private function outranks(User $actor, User $target): bool
    {
        if ($actor->can(Permission::AdminAssign->value)) {
            return true;
        }

        if ($target->hasPrivilegedRole()) {
            return false;
        }

        $held = $actor->getAllPermissions()->pluck('name');

        return $target->getAllPermissions()->pluck('name')->diff($held)->isEmpty();
    }
}
