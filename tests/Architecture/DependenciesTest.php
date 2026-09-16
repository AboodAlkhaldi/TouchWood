<?php

declare(strict_types=1);

/*
| Decisions recorded in the handoff (§3, amended 2026-09-16) that a `composer require` could
| silently undo.
*/

it('does not depend on packages the owner decided against', function (string $package, string $reason) {
    $root = dirname(__DIR__, 2);
    $composer = (string) file_get_contents($root.'/composer.json');
    $lock = (string) file_get_contents($root.'/composer.lock');

    // A file that could not be read must fail the guard, not pass it over nothing.
    expect($composer)->toContain('"laravel/framework"')
        ->and($lock)->toContain('"name": "laravel/framework"');

    expect($composer)->not->toContain("\"{$package}\"", $reason)
        ->and($lock)->not->toContain("\"name\": \"{$package}\"", $reason);
})->with([
    ['spatie/laravel-medialibrary', 'Media is Platform\'s own table: the package ties files to other modules\' models.'],
    ['brick/money', 'Money is our own value object with the exponent read from the currencies row.'],
]);
