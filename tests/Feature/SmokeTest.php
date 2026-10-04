<?php

use Illuminate\Support\Facades\DB;

it('runs against the PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('cockpit_test');
});
