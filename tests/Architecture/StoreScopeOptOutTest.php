<?php

/*
| Platform spec §1.6. Reading across stores is an explicit opt-out, allowed only in
| Application/Query read models and the Ops module.
*/

function usesStoreScopeOptOut(string $code): bool
{
    return preg_match('/(acrossStores|withoutGlobalScopes?)\s*\(/', $code) === 1;
}

it('detects an opt-out from the store scope', function (string $code) {
    expect(usesStoreScopeOptOut($code))->toBeTrue();
})->with([
    'across stores' => ['Order::query()->acrossStores()->get();'],
    'one scope removed' => ['$query->withoutGlobalScope(StoreScope::class);'],
    'all scopes removed' => ['$query->withoutGlobalScopes();'],
]);

it('ignores ordinary queries', function () {
    expect(usesStoreScopeOptOut('Order::query()->where("status", "NEW")->get();'))->toBeFalse();
});

it('allows reading across stores only in read models and Ops', function () {
    $root = (string) realpath(dirname(__DIR__, 2).'/src');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $violations = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        $allowed = $path === 'Shared/Infrastructure/Persistence/BelongsToStore.php'
            || preg_match('#^Modules/[^/]+/Application/Query/#', $path) === 1
            || str_starts_with($path, 'Modules/Ops/');

        if (! $allowed && usesStoreScopeOptOut((string) file_get_contents($file->getPathname()))) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});
