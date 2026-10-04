<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class LastRolesManagerGuard
{
    /**
     * Esegue la modifica in transazione. Se prima c'era almeno un utente
     * attivo con roles.manage (o con admin.assign) e dopo non ce n'è nessuno,
     * annulla tutto. I due conteggi sono indipendenti.
     */
    public static function protect(Closure $change): mixed
    {
        try {
            return DB::transaction(function () use ($change) {
                // Serializza le modifiche concorrenti: senza lock due richieste
                // potrebbero entrambe vedere "un altro gestore resta" e rimuoverli entrambi.
                DB::select('select pg_advisory_xact_lock(?)', [crc32('cockpit.roles-manage-guard')]);

                $managersBefore = self::holders(Permission::RolesManage);
                $assignersBefore = self::holders(Permission::AdminAssign);
                $result = $change();

                if ($managersBefore > 0 && self::holders(Permission::RolesManage) === 0) {
                    throw LockoutException::lastRolesManager();
                }

                if ($assignersBefore > 0 && self::holders(Permission::AdminAssign) === 0) {
                    throw LockoutException::lastAdminAssigner();
                }

                return $result;
            });
        } catch (Throwable $e) {
            // Il rollback non ripristina la cache di Spatie, già ricaricata
            // dallo stato interno alla transazione.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            throw $e;
        }
    }

    private static function holders(Permission $permission): int
    {
        PermissionModel::findOrCreate($permission->value, 'web');

        return User::query()
            ->where('status', UserStatus::Active->value)
            ->permission($permission->value)
            ->count();
    }
}
