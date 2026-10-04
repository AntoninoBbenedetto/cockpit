<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-004: roles.manage non basta più per toccare l'accesso privilegiato.
 * Gli admin esistenti mantengono i loro poteri ricevendo admin.assign.
 * Riallineamento di sistema: nessuna voce di audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $manage = Permission::query()->where('name', 'roles.manage')->where('guard_name', 'web')->first();

        // Installazione nuova: ci pensa il seeder.
        if ($manage === null) {
            return;
        }

        $assign = Permission::findOrCreate('admin.assign', 'web');

        $manage->roles->each(fn ($role) => $role->givePermissionTo($assign));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'admin.assign')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
