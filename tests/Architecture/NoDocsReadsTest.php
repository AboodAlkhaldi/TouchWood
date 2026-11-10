<?php

declare(strict_types=1);

/*
| The docs folder leaves the repository before staging (owner, 2026-11-10; catalog.md amendment 42):
| nothing the app runs, and no test, may read a file under docs/. What the app serves - the products
| import's guide and its examples - lives in the module that serves it.
*/

/**
 * Each quoted path into docs/ in the code, as `file:line`.
 *
 * @return list<string>
 */
function docsReadsIn(string $file, string $code): array
{
    $found = [];

    foreach (explode("\n", $code) as $index => $line) {
        if (preg_match('#[\'"`](?:\.\./|/)*docs/#', $line) === 1) {
            $found[] = $file.':'.($index + 1);
        }
    }

    return $found;
}

it('spots a quoted path into docs/', function (string $code) {
    expect(docsReadsIn('x.php', $code))->toBe(['x.php:1']);
})->with([
    'from the base' => ["file_get_contents(base_path('docs/modules/catalog-import/README.md'));"],
    'from a folder' => ["dirname(__DIR__, 4).'/docs/modules/catalog-import/'"],
    'in a template' => ['fetch(`../docs/guide.md`)'],
]);

it('leaves a mention of docs/ in prose alone', function () {
    expect(docsReadsIn('x.php', ' * (docs/modules/catalog.md §1.12)'))->toBe([]);
});

it('reads nothing under docs/ from the app or the tests', function () {
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'src', 'resources/js', 'tests'] as $folder) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! preg_match('/\.(php|ts|tsx)$/', $path) || str_ends_with($path, 'tests/Architecture/NoDocsReadsTest.php') || str_contains($path, '/resources/js/types/generated/')) {
                continue;
            }

            array_push($found, ...docsReadsIn(substr($path, strlen($root) + 1), (string) file_get_contents($path)));
        }
    }

    expect($found)->toBe([]);
});
