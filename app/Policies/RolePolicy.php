<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->manage($user);
    }

    private function manage(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }
}
