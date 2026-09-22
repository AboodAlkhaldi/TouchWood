<?php

declare(strict_types=1);

/*
| Stage 2b, P5. `app/Http` holds the framework glue that belongs to no module: the error renderer,
| the correlation-id middleware and the form-error helper. A module's Presentation layer may use it
| — it is the framework-facing layer — and no other layer may.
|
| deptrac cannot say that without splitting every module into a third layer, so it allows the glue
| to the module and this test narrows it, exactly as a framework-free Domain/ is enforced here
| rather than there (deptrac.yaml's own note).
*/

require_once __DIR__.'/helpers.php';

it('lets only a module\'s Presentation layer use the framework glue in app/Http', function () {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    $outside = [];
    $inside = 0;
    $scanned = 0;

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (preg_match('/^use App\\\\/m', codeWithoutComments($file->getPathname())) !== 1) {
            continue;
        }

        if (preg_match('#^src/Modules/[^/]+/Presentation/#', $path) === 1) {
            $inside++;

            continue;
        }

        $outside[] = $path;
    }

    // Guards against a moved directory making this test pass over nothing, and against the rule
    // being kept only because nothing uses the glue at all.
    expect($scanned)->toBeGreaterThan(400)
        ->and($inside)->toBeGreaterThan(0)
        ->and($outside)->toBe([]);
});

it('keeps the glue out of every module\'s public surface', function () {
    $root = dirname(__DIR__, 2);
    $public = glob($root.'/src/Modules/*/Public/**/*.php') ?: [];
    $using = [];

    expect(count($public))->toBeGreaterThan(20);

    foreach ($public as $file) {
        if (preg_match('/^use App\\\\/m', codeWithoutComments($file)) === 1) {
            $using[] = str_replace('\\', '/', substr($file, strlen($root) + 1));
        }
    }

    expect($using)->toBe([]);
});
