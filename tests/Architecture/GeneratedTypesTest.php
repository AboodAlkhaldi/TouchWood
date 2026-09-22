<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
| The TypeScript types are generated from the PHP that produces a page's data (frontend.md §1.6).
|
| A renamed field then breaks the type check rather than the live page. That only holds while the
| generated files in the repository actually match the PHP — a field renamed without regenerating
| leaves a type that agrees with nothing.
|
| So this regenerates them and compares. It does not compare timestamps: a fresh checkout gives
| every file the same one, and the check would pass over anything.
*/

uses(TestCase::class);

it('has the generated types the PHP would produce right now', function () {
    $directory = base_path('resources/js/types/generated');

    expect(is_dir($directory))->toBeTrue();

    $committed = generatedTypeFiles($directory);

    // Something must be there, or this test is about nothing.
    expect($committed)->not->toBeEmpty();

    Artisan::call('typescript:transform');
    $fresh = generatedTypeFiles($directory);

    // Put back whatever the run changed, so a failing test leaves the working tree as it found it.
    foreach (array_diff_key($fresh, $committed) as $path => $ignored) {
        @unlink($directory.'/'.$path);
    }

    foreach ($committed as $path => $contents) {
        file_put_contents($directory.'/'.$path, $contents);
    }

    expect(array_keys($fresh))->toBe(array_keys($committed), 'Run "php artisan typescript:transform" and commit the result.');

    foreach ($committed as $path => $contents) {
        expect($fresh[$path] ?? null)->toBe($contents, "resources/js/types/generated/{$path} is out of date. Run \"php artisan typescript:transform\".");
    }
});

/**
 * Every generated file, keyed by its path below the directory, so a failure names the file.
 *
 * @return array<string, string>
 */
function generatedTypeFiles(string $directory): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    $found = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'ts') {
            continue;
        }

        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        $found[$path] = (string) file_get_contents($file->getPathname());
    }

    ksort($found);

    return $found;
}
