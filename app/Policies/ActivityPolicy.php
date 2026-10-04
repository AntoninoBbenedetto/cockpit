<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Il registro di audit è immutabile dall'interfaccia: solo lettura.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::AuditView->value);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can(Permission::AuditView->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
