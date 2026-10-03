<?php

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\User;
use DomainException;

class SuspendUser
{
    public function handle(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new DomainException('Non puoi sospendere il tuo stesso account.');
        }

        LastRolesManagerGuard::protect(
            fn () => $target->forceFill(['status' => UserStatus::Suspended])->save()
        );
    }
}
