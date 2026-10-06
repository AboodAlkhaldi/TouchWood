<?php

declare(strict_types=1);

/*
| The JavaScript budgets of frontend.md §5, measured on the build: **200 KB gzipped shared** -
| everything every page downloads, cached once - and **60 KB gzipped for one page** on top of it.
| "A breach fails the build": a warning that stays green is ignored, which is how the slow system
| happened (handoff §5.4).
|
| Measured from Vite's manifest, the way a browser meets the files: the app's entry and every chunk
| it imports statically are shared, and so is a chunk that every page loads - it is downloaded once
| and cached, whichever page comes first; a page is its own chunk plus whatever it alone pulls in.
| (Batch A of the shadcn rebuild, 2026-10-03: the bundler had put shadcn's common Radix code, which
| every page pulls in, in chunks of its own, and counting them on every page made each page pay for
| the same cached code.) A page is a file Inertia opens - one with a default export; a helper file
| beside the pages counts inside the pages that import it. Sizes are gzipped at the strongest level
| and counted in kilobytes of 1,000 bytes, as Vite reports them.
|
| It reads public/build, so it measures the last build - and refuses one older than the code it was
| built from, which would let a breach pass unmeasured (the review of the foundation, 2026-10-03).
| CI and the check worktree build before they test; run `npm run build` first locally.
|
| What a page loads only later, by its own dynamic import, is not counted: the budget is what a page
| needs before it shows, and §5 asks for heavy things - a chart, an editor - to load that way.
*/

const SHARED_BUDGET = 200_000;
const PAGE_BUDGET = 60_000;
const APP_ENTRY = 'resources/js/app.tsx';

/**
 * Every manifest key a chunk loads with it, itself included, following static imports only.
 *
 * @param  array<string, array{file: string, imports?: list<string>}>  $manifest
 * @return list<string>
 */
function chunksLoadedWith(array $manifest, string $key): array
{
    $seen = [];
    $queue = [$key];

    while ($queue !== []) {
        $next = array_shift($queue);

        if (isset($seen[$next]) || ! isset($manifest[$next])) {
            continue;
        }

        $seen[$next] = true;
        array_push($queue, ...($manifest[$next]['imports'] ?? []));
    }

    return array_keys($seen);
}

/**
 * The shared size and each page's own size, in gzipped bytes.
 *
 * A page is a file Inertia can open - one with a default export. The app's page glob also makes an
 * entry of every helper module beside the pages (an account tab, a form's parts); nobody opens one
 * of those on its own, so it is counted inside the pages that import it and is not a page itself
 * (the review of batch A; 39 pages among 59 files on 2026-10-04).
 *
 * @param  array<string, array{file: string, src?: string, imports?: list<string>, isDynamicEntry?: bool}>  $manifest
 * @param  callable(string): int  $gzippedSize  the gzipped size of a built file
 * @param  (callable(string): bool)|null  $isPage  whether a source file is a page; every entry, if not given
 * @return array{shared: int, pages: array<string, int>}
 */
function javaScriptSizes(array $manifest, callable $gzippedSize, ?callable $isPage = null): array
{
    $sizeOf = fn (array $keys): int => array_sum(array_map(fn (string $key): int => $gzippedSize($manifest[$key]['file']), $keys));

    /** @var array<string, list<string>> $loaded */
    $loaded = [];

    foreach ($manifest as $key => $chunk) {
        $source = $chunk['src'] ?? $key;

        if (($chunk['isDynamicEntry'] ?? false) && str_starts_with($source, 'resources/js/pages/') && ($isPage === null || $isPage($source))) {
            $loaded[$key] = chunksLoadedWith($manifest, $key);
        }
    }

    // What every page loads is downloaded once, whichever page comes first. With one page only,
    // nothing is "every page's" but the entry's: its own chunk would otherwise count as shared.
    $everyPage = count($loaded) < 2 ? [] : array_values(array_intersect(...array_values($loaded)));
    $shared = array_values(array_unique([...chunksLoadedWith($manifest, APP_ENTRY), ...$everyPage]));

    return [
        'shared' => $sizeOf($shared),
        'pages' => array_map(fn (array $keys): int => $sizeOf(array_values(array_diff($keys, $shared))), $loaded),
    ];
}

it('counts a chunk the entry imports as shared, and a page only for what it alone adds', function () {
    $manifest = [
        APP_ENTRY => ['file' => 'app.js', 'imports' => ['_react.js']],
        '_react.js' => ['file' => 'react.js'],
        '_table.js' => ['file' => 'table.js', 'imports' => ['_react.js']],
        'resources/js/pages/A.tsx' => ['file' => 'a.js', 'src' => 'resources/js/pages/A.tsx', 'isDynamicEntry' => true, 'imports' => ['_react.js', '_table.js']],
        'resources/js/pages/B.tsx' => ['file' => 'b.js', 'src' => 'resources/js/pages/B.tsx', 'isDynamicEntry' => true, 'imports' => ['_react.js']],
    ];
    $sizes = ['app.js' => 10, 'react.js' => 100, 'table.js' => 30, 'a.js' => 5, 'b.js' => 7];

    expect(javaScriptSizes($manifest, fn (string $file): int => $sizes[$file]))->toBe([
        'shared' => 110,
        'pages' => ['resources/js/pages/A.tsx' => 35, 'resources/js/pages/B.tsx' => 7],
    ]);
});

it('counts a chunk every page loads as shared, and one that only some pages load against each of them', function () {
    $manifest = [
        APP_ENTRY => ['file' => 'app.js'],
        '_radix.js' => ['file' => 'radix.js'],
        '_layout.js' => ['file' => 'layout.js', 'imports' => ['_radix.js']],
        'resources/js/pages/A.tsx' => ['file' => 'a.js', 'src' => 'resources/js/pages/A.tsx', 'isDynamicEntry' => true, 'imports' => ['_layout.js']],
        'resources/js/pages/B.tsx' => ['file' => 'b.js', 'src' => 'resources/js/pages/B.tsx', 'isDynamicEntry' => true, 'imports' => ['_layout.js']],
        'resources/js/pages/C.tsx' => ['file' => 'c.js', 'src' => 'resources/js/pages/C.tsx', 'isDynamicEntry' => true, 'imports' => ['_radix.js']],
    ];
    $sizes = ['app.js' => 10, 'radix.js' => 50, 'layout.js' => 20, 'a.js' => 5, 'b.js' => 7, 'c.js' => 3];

    // radix.js reaches every page, so it is shared; layout.js reaches two of three, so each of
    // those two pays for it.
    expect(javaScriptSizes($manifest, fn (string $file): int => $sizes[$file]))->toBe([
        'shared' => 60,
        'pages' => ['resources/js/pages/A.tsx' => 25, 'resources/js/pages/B.tsx' => 27, 'resources/js/pages/C.tsx' => 3],
    ]);
});

it('does not count a lone page as shared with itself', function () {
    $manifest = [
        APP_ENTRY => ['file' => 'app.js'],
        'resources/js/pages/A.tsx' => ['file' => 'a.js', 'src' => 'resources/js/pages/A.tsx', 'isDynamicEntry' => true],
    ];

    expect(javaScriptSizes($manifest, fn (string $file): int => ['app.js' => 10, 'a.js' => 5][$file]))
        ->toBe(['shared' => 10, 'pages' => ['resources/js/pages/A.tsx' => 5]]);
});

it('counts a helper module beside the pages inside the page that imports it, not as a page', function () {
    $manifest = [
        APP_ENTRY => ['file' => 'app.js'],
        '_radix.js' => ['file' => 'radix.js'],
        'resources/js/pages/Account.tsx' => ['file' => 'account.js', 'src' => 'resources/js/pages/Account.tsx', 'isDynamicEntry' => true, 'imports' => ['_radix.js', 'resources/js/pages/Tab.tsx']],
        'resources/js/pages/Other.tsx' => ['file' => 'other.js', 'src' => 'resources/js/pages/Other.tsx', 'isDynamicEntry' => true, 'imports' => ['_radix.js']],
        // The glob makes an entry of the tab too, though only the account page ever loads it.
        'resources/js/pages/Tab.tsx' => ['file' => 'tab.js', 'src' => 'resources/js/pages/Tab.tsx', 'isDynamicEntry' => true],
    ];
    $sizes = ['app.js' => 10, 'radix.js' => 50, 'account.js' => 5, 'other.js' => 3, 'tab.js' => 20];
    $pages = ['resources/js/pages/Account.tsx', 'resources/js/pages/Other.tsx'];

    // As a page, the tab - which loads no radix - would keep radix from being every page's.
    expect(javaScriptSizes($manifest, fn (string $file): int => $sizes[$file], fn (string $source): bool => in_array($source, $pages, true)))->toBe([
        'shared' => 60,
        'pages' => ['resources/js/pages/Account.tsx' => 25, 'resources/js/pages/Other.tsx' => 3],
    ]);
});

/**
 * The files the build is made from that changed after it was made: the code and styles, the Vite
 * config and the lock file. At most five, named, which is enough to say what to rebuild for.
 *
 * @return list<string>
 */
function javaScriptSourcesNewerThan(int $built): array
{
    $root = dirname(__DIR__, 2);
    $newer = [];
    $files = [$root.'/vite.config.ts', $root.'/package-lock.json'];

    foreach ([$root.'/resources/js', $root.'/resources/css'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            // Types only: they add nothing to the build, and GeneratedTypesTest writes the generated
            // ones afresh on every run, which would make every build look old.
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/resources/js/types/')) {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) > $built) {
            $newer[] = str_replace('\\', '/', substr($file, strlen($root) + 1));
        }
    }

    return array_slice($newer, 0, 5);
}

it('keeps the JavaScript within the budgets of frontend.md §5', function () {
    $build = dirname(__DIR__, 2).'/public/build';
    $manifestPath = $build.'/manifest.json';

    expect(is_file($manifestPath))->toBeTrue('public/build/manifest.json is missing: run npm run build first');

    $newer = javaScriptSourcesNewerThan((int) filemtime($manifestPath));

    expect($newer)->toBe([], 'the build is older than the code it was built from: run npm run build first');

    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toHaveKey(APP_ENTRY);

    $sizes = javaScriptSizes(
        $manifest,
        fn (string $file): int => strlen((string) gzencode((string) file_get_contents($build.'/'.$file), 9)),
        // A page is a file with a default export: what Inertia renders. Either way of writing one
        // counts - `export default` or `export { X as default }` - so a page is never dropped from
        // the measure for how its export is spelled (the batch B review).
        fn (string $source): bool => preg_match('/^export\s+(default\b|\{[^}]*\bas\s+default\b)/m', (string) file_get_contents(dirname(__DIR__, 2).'/'.$source)) === 1,
    );
    $overPages = array_filter($sizes['pages'], fn (int $size): bool => $size > PAGE_BUDGET);
    arsort($overPages);

    expect($sizes['pages'])->not->toBeEmpty('no page chunks found: are the pages still bundled eagerly?')
        ->and($sizes['shared'])->toBeLessThanOrEqual(SHARED_BUDGET, sprintf('shared JavaScript is %.1f KB gzipped, over the 200 KB budget', $sizes['shared'] / 1000))
        ->and(array_map(fn (int $size): string => sprintf('%.1f KB', $size / 1000), $overPages))->toBe([]);
});
