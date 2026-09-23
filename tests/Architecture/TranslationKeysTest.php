<?php

declare(strict_types=1);

/*
| Every word a screen asks for exists in both languages (frontend.md §1.5).
|
| There are no frontend translation files: a screen reads Laravel's lang files through one t() call,
| so a key it asks for and never receives renders as the key itself — "access::auth.sign_in" sitting
| in the middle of a button. This is what stops that reaching a person.
|
| It reads the keys out of the React source rather than trusting a list, so a key added to a screen
| is checked the moment it is written.
*/

/**
 * The keys a piece of React source asks for: t('access::auth.sign_in'), t(`admin.theme.${next}`).
 *
 * A key built from a variable cannot be read here, so those are returned as a prefix with a `*`,
 * and checked as "something under this prefix must exist" rather than skipped in silence.
 *
 * @return array{keys: list<string>, prefixes: list<string>}
 */
function translationKeysIn(string $code): array
{
    $keys = [];
    $prefixes = [];

    // t('a.b.c') and t("a.b.c")
    preg_match_all('/\bt\(\s*[\'"]([a-z0-9_:.]+)[\'"]/i', $code, $plain);
    $keys = $plain[1];

    // t(`a.b.${something}`) — everything before the first interpolation.
    preg_match_all('/\bt\(\s*`([a-z0-9_:.]+)\$\{/i', $code, $built);

    foreach ($built[1] as $prefix) {
        $prefixes[] = rtrim($prefix, '.');
    }

    return ['keys' => array_values(array_unique($keys)), 'prefixes' => array_values(array_unique($prefixes))];
}

it('reads the keys a screen asks for', function () {
    $found = translationKeysIn(<<<'TSX'
        const a = t('access::auth.sign_in');
        const b = t("admin.home.title");
        const c = t(`admin.theme.${next}`);
        const d = t('access::auth.trust_browser', { days: 30 });
        TSX);

    expect($found['keys'])->toBe([
        'access::auth.sign_in',
        'admin.home.title',
        'access::auth.trust_browser',
    ])->and($found['prefixes'])->toBe(['admin.theme']);
});

/**
 * The lines of one translation file, flattened, or null when there is no such file.
 *
 * Resolved from disk rather than through Laravel's translator: an architecture test runs without an
 * application, and reading the files is also what makes the failure name the file to open.
 * "access::auth" is a module's own file; "admin" is the application's.
 *
 * @return array<string, string>|null
 */
function translationFile(string $group, string $locale): ?array
{
    $root = dirname(__DIR__, 2);

    if (str_contains($group, '::')) {
        [$module, $file] = explode('::', $group, 2);
        $path = $root.'/src/Modules/'.ucfirst($module).'/Presentation/lang/'.$locale.'/'.$file.'.php';
    } else {
        $path = $root.'/lang/'.$locale.'/'.$group.'.php';
    }

    if (! is_file($path)) {
        return null;
    }

    $lines = (array) require $path;
    $flat = [];

    foreach (flatKeys($lines) as $key) {
        $flat[$key] = $key;
    }

    return $flat;
}

/**
 * Whether a full key - "access::auth.sign_in" - has a line behind it in this language.
 */
function translationExists(string $key, string $locale): bool
{
    $dot = strpos($key, '.');

    if ($dot === false) {
        return false;
    }

    $file = translationFile(substr($key, 0, $dot), $locale);

    return $file !== null && isset($file[substr($key, $dot + 1)]);
}

it('resolves a key to the file behind it', function () {
    expect(translationExists('access::auth.sign_in', 'en'))->toBeTrue()
        ->and(translationExists('access::auth.sign_in', 'ar'))->toBeTrue()
        ->and(translationExists('admin.home.title', 'en'))->toBeTrue()
        ->and(translationExists('access::auth.no_such_line', 'en'))->toBeFalse()
        ->and(translationExists('nosuchfile.at_all', 'en'))->toBeFalse()
        ->and(translationExists('nodot', 'en'))->toBeFalse();
});

it('has every word a screen asks for, in Arabic and in English', function () {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS));

    $missing = [];
    $asked = 0;

    foreach ($files as $file) {
        if ($file->getExtension() !== 'tsx' && $file->getExtension() !== 'ts') {
            continue;
        }

        $where = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $found = translationKeysIn((string) file_get_contents($file->getPathname()));

        foreach ($found['keys'] as $key) {
            $asked++;

            foreach (['ar', 'en'] as $locale) {
                if (! translationExists($key, $locale)) {
                    $missing[] = "{$where}: {$key} ({$locale})";
                }
            }
        }

        foreach ($found['prefixes'] as $prefix) {
            $asked++;

            foreach (['ar', 'en'] as $locale) {
                // A key built from a variable cannot be read in full, so what is checked is that
                // something exists under the prefix - t(`admin.theme.${next}`) needs admin.theme.*
                // to be a real group with lines in it.
                // Split at the FIRST dot, as a full key is: "admin.theme.switch_to_" lives in the
                // file "admin" under "theme.switch_to_", not in a file called "admin.theme".
                $dot = strpos($prefix, '.');
                $file = $dot === false ? null : translationFile(substr($prefix, 0, $dot), $locale);
                $under = $dot === false ? '' : substr($prefix, $dot + 1);

                $anything = $file !== null && array_filter(
                    array_keys($file),
                    static fn (string $key): bool => str_starts_with($key, $under),
                ) !== [];

                if (! $anything) {
                    $missing[] = "{$where}: {$prefix}.* ({$locale})";
                }
            }
        }
    }

    // Guards against a moved directory making this pass over nothing.
    expect($asked)->toBeGreaterThan(30)
        ->and($missing)->toBe([]);
});

it('says the same things in both languages', function () {
    // A line added to one language and forgotten in the other is the commonest way a screen ends up
    // showing a key. Compared file by file, so the message names the file that is behind.
    $root = dirname(__DIR__, 2);
    $differences = [];
    $compared = 0;

    $directories = [
        'lang' => $root.'/lang',
        ...array_combine(
            array_map(fn (string $path): string => basename(dirname($path, 2)), glob($root.'/src/Modules/*/Presentation/lang', GLOB_ONLYDIR) ?: []),
            glob($root.'/src/Modules/*/Presentation/lang', GLOB_ONLYDIR) ?: [],
        ),
    ];

    foreach ($directories as $where => $directory) {
        foreach (glob($directory.'/en/*.php') ?: [] as $english) {
            $name = basename($english);
            $arabic = $directory.'/ar/'.$name;
            $compared++;

            if (! file_exists($arabic)) {
                $differences[] = "{$where}/{$name}: no Arabic file at all";

                continue;
            }

            $inEnglish = flatKeys((array) require $english);
            $inArabic = flatKeys((array) require $arabic);

            foreach (array_diff($inEnglish, $inArabic) as $key) {
                $differences[] = "{$where}/{$name}: {$key} is missing in Arabic";
            }

            foreach (array_diff($inArabic, $inEnglish) as $key) {
                $differences[] = "{$where}/{$name}: {$key} is missing in English";
            }
        }
    }

    expect($compared)->toBeGreaterThan(5)
        ->and($differences)->toBe([]);
});

/**
 * @param  array<array-key, mixed>  $lines
 * @return list<string>
 */
function flatKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = [...$keys, ...flatKeys($value, $full)];

            continue;
        }

        $keys[] = $full;
    }

    return $keys;
}
