<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

it('registers a policy for the model of every filament resource', function () {
    $resources = Finder::create()->files()->name('*Resource.php')->in(app_path('Filament/Resources'));

    expect(iterator_count($resources))->toBeGreaterThan(0);

    foreach ($resources as $file) {
        $class = 'App\\Filament\\Resources\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        expect(Gate::getPolicyFor($class::getModel()))->not->toBeNull("{$class} has no policy");
    }
});

it('registers every policy class', function () {
    $registered = array_values(Gate::policies());

    foreach (Finder::create()->files()->name('*Policy.php')->in(app_path('Policies')) as $file) {
        $class = 'App\\Policies\\'.$file->getBasename('.php');

        expect($registered)->toContain($class);
    }
});

it('names permissions as resource.action and keeps them unique', function () {
    $values = Permission::values();

    expect($values)->toBe(array_values(array_unique($values)));

    foreach ($values as $value) {
        expect($value)->toMatch('/^[a-z_]+(\.[a-z_]+)+$/');
    }
});
