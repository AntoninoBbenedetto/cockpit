<?php

namespace App\Enums;

enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersSuspend = 'users.suspend';
    case UsersDelete = 'users.delete';
    case RolesManage = 'roles.manage';
    case AdminAssign = 'admin.assign';
    case SettingsGeneralUpdate = 'settings.general.update';
    case AuditView = 'audit.view';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Permessi che rendono privilegiato un ruolo (ADR-004).
     *
     * @return array<int, string>
     */
    public static function privilegedValues(): array
    {
        return [self::RolesManage->value, self::AdminAssign->value];
    }
}
