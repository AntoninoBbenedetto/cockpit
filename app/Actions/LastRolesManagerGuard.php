<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;

final class LastRolesManagerGuard
{
    /**
     * Esegue la modifica in transazione. Se prima c'era almeno un utente
     * attivo con roles.manage e dopo non ce n'è nessuno, annulla tutto.
     */
    public static function protect(Closure $change): mixed
    {
        return DB::transaction(function () use ($change) {
            $before = self::managers();
            $result = $change();

            if ($before > 0 && self::managers() === 0) {
                throw LockoutException::lastRolesManager();
            }

            return $result;
        });
    }

    private static function managers(): int
    {
        PermissionModel::findOrCreate(Permission::RolesManage->value, 'web');

        return User::query()
            ->where('status', UserStatus::Active->value)
            ->permission(Permission::RolesManage->value)
            ->count();
    }
}
