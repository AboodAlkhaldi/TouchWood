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

/**
 * Whether a file under src/ may use the framework glue in app/Http: only a module's Presentation
 * layer may. Named, so the rule itself can be tested rather than only applied.
 */
function appHttpIsAllowedIn(string $path): bool
{
    return preg_match('#^src/Modules/[^/]+/Presentation/#', $path) === 1;
}

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

        if (appHttpIsAllowedIn($path)) {
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
    // Walked, not globbed: PHP's glob has no globstar, so 'Public/**/*.php' matches exactly one
    // level down and silently skips a file sitting directly in Public/ — PlatformPermissions.php
    // among them (review of step 0).
    $using = [];
    $public = 0;

    foreach (glob($root.'/src/Modules/*/Public', GLOB_ONLYDIR) ?: [] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $public++;

            if (preg_match('/^use App\\\\/m', codeWithoutComments($file->getPathname())) === 1) {
                $using[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    expect($public)->toBeGreaterThan(50);

    expect($using)->toBe([]);
});

it('allows the glue in the Presentation layer of a module and nowhere else', function (string $path, bool $allowed) {
    // The rule the guard above applies, proved against paths rather than trusted: a guard whose own
    // rule is wrong passes over everything (review of stage 2b step 0).
    expect(appHttpIsAllowedIn($path))->toBe($allowed);
})->with([
    'a controller' => ['src/Modules/Access/Presentation/Http/Controller/StaffSignInController.php', true],
    'a console command' => ['src/Modules/Access/Presentation/Console/CreateSuperAdminCommand.php', true],
    'an application handler' => ['src/Modules/Access/Application/Command/SignInStaff/SignInStaffHandler.php', false],
    'a domain model' => ['src/Modules/Access/Domain/Model/StaffUser.php', false],
    'infrastructure' => ['src/Modules/Access/Infrastructure/Http/RequestActor.php', false],
    'a public contract' => ['src/Modules/Access/Public/Contracts/AccessApi.php', false],
    'the shared kernel' => ['src/Shared/Application/Authorizer.php', false],
    'a folder merely named Presentation' => ['src/Shared/Presentation/Thing.php', false],
]);
