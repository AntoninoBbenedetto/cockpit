<?php

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\User;

class ReactivateUser
{
    public function handle(User $target): void
    {
        $target->forceFill(['status' => UserStatus::Active])->save();
    }
}
