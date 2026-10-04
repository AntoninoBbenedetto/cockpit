<?php

use App\Actions\LastRolesManagerGuard;
use App\Actions\PrivilegedAccessGuard;
use App\Models\User;

// Presentation layer isolato: Filament non contiene logica di dominio.
arch('filament does not touch infrastructure facades')
    ->expect('App\Filament')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Http',
        'Illuminate\Support\Facades\Queue',
    ]);

arch('domain layers do not depend on filament')
    ->expect(['App\Actions', 'App\Policies'])
    ->not->toUse('Filament');

arch('models do not depend on filament except the panel user')
    ->expect('App\Models')
    ->not->toUse('Filament')
    ->ignoring(User::class);

arch('actions expose handle()')
    ->expect('App\Actions')
    ->toHaveMethod('handle')
    ->ignoring([LastRolesManagerGuard::class, PrivilegedAccessGuard::class]);

arch('actions do not depend on the http layer')
    ->expect('App\Actions')
    ->not->toUse('Illuminate\Http');

arch('policies are suffixed and independent from the ui')
    ->expect('App\Policies')
    ->toHaveSuffix('Policy')
    ->not->toUse('App\Filament');

arch('enums are string backed enums')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('settings extend the settings base class')
    ->expect('App\Settings')
    ->toExtend('Spatie\LaravelSettings\Settings');

// Complessità solo quando serve: niente code, Redis, API.
arch('no queues or redis until needed')
    ->expect('App')
    ->not->toUse([
        'Illuminate\Contracts\Queue\ShouldQueue',
        'Illuminate\Support\Facades\Queue',
        'Illuminate\Support\Facades\Redis',
    ]);

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'ddd'])
    ->not->toBeUsed();
