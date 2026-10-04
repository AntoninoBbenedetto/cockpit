<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Regola ADR-004: toccare l'accesso privilegiato (ruoli con roles.manage o
 * admin.assign) richiede admin.assign, non solo roles.manage.
 * Va chiamata dentro LastRolesManagerGuard::protect, prima della modifica.
 */
final class PrivilegedAccessGuard
{
    /**
     * @param  array<int, string>  $before  nomi dei ruoli prima
     * @param  array<int, string>  $after  nomi dei ruoli dopo
     */
    public static function ensureCanChangeRoles(User $actor, array $before, array $after): void
    {
        $changed = array_merge(array_diff($before, $after), array_diff($after, $before));

        if ($changed === [] || self::canAssign($actor)) {
            return;
        }

        $touchesPrivileged = Role::query()->whereIn('name', $changed)->get()
            ->contains(fn (Role $role) => $role->isPrivileged());

        if ($touchesPrivileged) {
            throw new AuthorizationException('Per assegnare o revocare ruoli amministrativi serve il permesso admin.assign.');
        }
    }

    /**
     * @param  array<int, string>  $before  nomi dei permessi del ruolo prima
     * @param  array<int, string>  $after  nomi dei permessi del ruolo dopo
     */
    public static function ensureCanChangePermissions(User $actor, array $before, array $after): void
    {
        $privileged = Permission::privilegedValues();
        $touches = array_intersect($before, $privileged) !== [] || array_intersect($after, $privileged) !== [];

        if ($touches && ! self::canAssign($actor)) {
            throw new AuthorizationException('Per modificare un ruolo amministrativo serve il permesso admin.assign.');
        }
    }

    public static function ensureCanDeleteRole(User $actor, Role $role): void
    {
        if (! self::canAssign($actor) && $role->isPrivileged()) {
            throw new AuthorizationException('Per eliminare un ruolo amministrativo serve il permesso admin.assign.');
        }
    }

    private static function canAssign(User $actor): bool
    {
        return $actor->can(Permission::AdminAssign->value);
    }
}
