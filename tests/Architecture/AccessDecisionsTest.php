<?php

declare(strict_types=1);

/*
| Access spec §8: two decisions that a later change could undo quietly.
|
| Permissions are our own tables, never spatie/laravel-permission, because a role's store scope
| lives on the role itself; and no controller checks a permission — the handler does, so the same
| rule holds whether the action arrives over HTTP, from the console or from a queued job.
*/

require_once __DIR__.'/helpers.php';

it('never depends on spatie/laravel-permission', function () {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

    expect($composer)->toBeArray()
        ->and(array_keys($composer['require'] ?? []))->not->toContain('spatie/laravel-permission')
        ->and(array_keys($composer['require-dev'] ?? []))->not->toContain('spatie/laravel-permission')
        ->and(is_dir($root.'/vendor/spatie/laravel-permission'))->toBeFalse();
});

it('has no controller that checks a permission itself', function () {
    $root = dirname(__DIR__, 2);
    $controllers = glob($root.'/src/Modules/*/Presentation/Http/Controller/*.php') ?: [];
    $checking = [];

    // Guards against a moved directory making this test pass over nothing.
    expect(count($controllers))->toBeGreaterThanOrEqual(5);

    foreach ($controllers as $controller) {
        $code = codeWithoutComments($controller);

        // Laravel's own ways of asking, and ours: a controller uses none of them.
        if (preg_match('/->(authorize|allows|denies|storesWith)\s*\(|Gate::|\$this->authorize\s*\(/', $code) === 1) {
            $checking[] = str_replace('\\', '/', substr($controller, strlen($root) + 1));
        }
    }

    expect($checking)->toBe([]);
});
