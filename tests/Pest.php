<?php

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Arch');

function userWith(Permission ...$permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(PermissionModel::findOrCreate($permission->value, 'web'));
    }

    return $user;
}
