<?php

namespace App\Actions;

use App\Models\User;
use DomainException;

class DeleteUser
{
    public function handle(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new DomainException('Non puoi eliminare il tuo stesso account.');
        }

        LastRolesManagerGuard::protect(fn () => $target->delete());
    }
}
