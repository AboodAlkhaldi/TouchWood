<?php

declare(strict_types=1);

/*
| The docs folder leaves the repository before staging (owner, 2026-11-10; catalog.md amendment 43):
| nothing the app runs, and no test, may read a file under docs/. What the app serves - the products
| import's guide and its examples - lives in the module that serves it.
*/

/**
 * Each path into docs/ the code builds, as `file:line`: the word as a path's first part - right after a
 * quote or the `}` that closes an interpolation, behind any `/`, `./` or `../` - followed by a `/` or by
 * the closing quote (base_path of 'docs', a folder joined to 'docs'); in a template literal, followed by
 * a `/`. Prose that names the folder without a quote before it, or in backticks alone, is left alone.
 *
 * @return list<string>
 */
function docsReadsIn(string $file, string $code): array
{
    $found = [];

    foreach (explode("\n", $code) as $index => $line) {
        if (preg_match('#[\'"}](?:\.{0,2}/)*docs(?:/|[\'"])|`(?:\.{0,2}/)*docs/#', $line) === 1) {
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
    'the folder itself, from the base' => ["base_path('docs')"],
    'the folder, then a file' => ["base_path('docs').'/modules/catalog.md'"],
    'after an interpolation' => ['"{$root}/docs/modules/catalog.md"'],
    'the bare word as a path part' => ["\$root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'modules'"],
    'one part of a list' => ["implode('/', [\$root, \"docs\", 'modules'])"],
    'from the current folder' => ["'./docs/guide.md'"],
]);

it('leaves a mention of docs/ in prose alone', function (string $code) {
    expect(docsReadsIn('x.php', $code))->toBe([]);
})->with([
    'a spec in a comment' => [' * (docs/modules/catalog.md §1.12)'],
    'the word in a sentence' => [' // the docs folder leaves GitHub; see the docs'],
    'a site\'s docs' => [' /** MyFatoorah\'s answer (docs.myfatoorah.com/docs/api-key). */'],
    'a word that starts with docs' => ["\$kind = 'docsets';"],
    'a quoted sentence about docs' => ["'Read the docs first'"],
    'the folder named in backticks' => [' * the `docs` folder need not ship'],
]);

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
