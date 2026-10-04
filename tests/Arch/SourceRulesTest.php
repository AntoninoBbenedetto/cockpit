<?php

use Symfony\Component\Finder\Finder;

/**
 * Cerca un pattern nei file PHP sotto $dir e restituisce "file:riga" delle occorrenze.
 *
 * @return array<int, string>
 */
function occurrencesIn(string $dir, string $pattern): array
{
    $hits = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/'.$dir) as $file) {
        foreach (file($file->getRealPath()) as $i => $line) {
            if (preg_match($pattern, $line)) {
                $hits[] = $file->getRelativePathname().':'.($i + 1);
            }
        }
    }

    return $hits;
}

it('checks permissions, never roles (ADR-002)', function () {
    expect(occurrencesIn('app', '/->(hasRole|hasAnyRole|hasAllRoles|hasExactRoles)\(|Gate::before/'))->toBe([]);
});

it('logs audit fields explicitly, never everything', function () {
    expect(occurrencesIn('app', '/->(logAll|logUnguarded)\(/'))->toBe([]);
});

it('keeps database transactions out of filament', function () {
    expect(occurrencesIn('app/Filament', '/\bDB::/'))->toBe([]);
});

it('has no REST API or controllers beyond the base class', function () {
    $controllers = array_map(
        fn ($file) => $file->getRelativePathname(),
        iterator_to_array(Finder::create()->files()->in(dirname(__DIR__, 2).'/app/Http/Controllers'), false),
    );

    expect($controllers)->toBe(['Controller.php'])
        ->and(file_exists(dirname(__DIR__, 2).'/routes/api.php'))->toBeFalse();
});
