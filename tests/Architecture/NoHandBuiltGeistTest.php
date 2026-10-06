<?php

declare(strict_types=1);

/*
| The hand-built Geist components are gone and stay gone (frontend.md §1.11, owner 2026-10-02:
| "dont ever create something if they had it"). Every screen is built from shadcn's code, from its
| CLI, in `components/ui`, with Geist's look and rules on top; the few pieces neither system has,
| built from Geist's own pages, live in `components/geist-only`. A folder of look-alikes coming back -
| or a page importing one - is the mistake this stage undid, so it fails here rather than in review.
*/

const HAND_BUILT_GEIST = 'resources/js/components/geist';

/**
 * Every script under resources/js, relative to the project.
 *
 * @return list<string>
 */
function frontendScripts(): array
{
    $project = dirname(__DIR__, 2);
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project.'/resources/js', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && preg_match('/\.(ts|tsx)$/', $file->getFilename()) === 1) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($project) + 1));
        }
    }

    sort($files);

    return $files;
}

it('has no hand-built Geist components folder', function () {
    expect(is_dir(dirname(__DIR__, 2).'/'.HAND_BUILT_GEIST))->toBeFalse(HAND_BUILT_GEIST.' is back: build from shadcn (components/ui), or put a piece neither system has in components/geist-only');
});

it('imports nothing from a hand-built Geist folder', function () {
    $importers = array_values(array_filter(
        frontendScripts(),
        fn (string $path): bool => preg_match('#from\s+[\'"][^\'"]*components/geist[\'"/]#', (string) file_get_contents(dirname(__DIR__, 2).'/'.$path)) === 1,
    ));

    expect($importers)->toBe([]);
});

it('finds an import of the old folder, and leaves geist-only alone', function () {
    $pattern = '#from\s+[\'"][^\'"]*components/geist[\'"/]#';

    expect(preg_match($pattern, "import { Button } from '@/components/geist';"))->toBe(1)
        ->and(preg_match($pattern, "import { Time } from '@/components/geist/Time';"))->toBe(1)
        ->and(preg_match($pattern, "import { CopyButton } from '@/components/geist-only/CopyButton';"))->toBe(0);
});
