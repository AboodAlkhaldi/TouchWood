<?php

declare(strict_types=1);

/*
| Every type size the code names is one app.css defines (frontend.md §1.11, Geist's type scale). Tailwind turns a class
| it has no utility for into nothing, silently: `text-copy-12` was written 76 times in 44 files while app.css defined
| copy-13 and up only, so each of those texts drew at whatever size it inherited (PR 11's review, 2026-11-11). The
| build passes, the types pass and the page looks nearly right, so only a test sees it.
|
| The scale's four families are checked: heading, label, copy and button, with their `-mono` forms. Tailwind's own
| sizes (`text-sm`) and the colour tokens (`text-ink-muted`) are not this scale and are left to Tailwind.
*/

const TYPE_SCALE_CLASS = '/(?<![\w-])text-(?:heading|label|copy|button)-\d+(?:-mono)?(?![\w-])/';

/**
 * The type-scale classes a piece of code names.
 *
 * @return list<string>
 */
function typeScaleClassesIn(string $code): array
{
    preg_match_all(TYPE_SCALE_CLASS, $code, $matches);

    return array_values(array_unique($matches[0]));
}

/**
 * The type-scale utilities a stylesheet defines, as `@utility text-copy-13 { … }`.
 *
 * @return list<string>
 */
function typeScaleUtilitiesIn(string $css): array
{
    preg_match_all('/@utility\s+(text-(?:heading|label|copy|button)-\d+(?:-mono)?)\s*\{/', $css, $matches);

    return array_values(array_unique($matches[1]));
}

it('finds the type-scale classes in code, and only those', function () {
    expect(typeScaleClassesIn('className="tw-figure text-copy-12 text-ink-muted text-label-14-mono sm:text-heading-20"'))
        ->toBe(['text-copy-12', 'text-label-14-mono', 'text-heading-20'])
        ->and(typeScaleClassesIn('className="text-sm text-ink text-center text-copy text-label-12x my-text-copy-13"'))->toBe([]);
});

it('reads the utilities app.css defines', function () {
    expect(typeScaleUtilitiesIn("@utility text-copy-13 { font-size: 13px; }\n@utility text-label-12-mono { x: y; }\n@utility material-base { }"))
        ->toBe(['text-copy-13', 'text-label-12-mono']);
});

it('names only type sizes app.css defines', function () {
    $root = dirname(__DIR__, 2);
    $defined = typeScaleUtilitiesIn((string) file_get_contents($root.'/resources/css/app.css'));
    $undefined = [];
    $scanned = 0;

    // The scale itself is there to check against: a guard reading nothing passes over everything.
    expect($defined)->toContain('text-copy-13', 'text-label-12', 'text-heading-16');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $scanned++;
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        foreach (typeScaleClassesIn((string) file_get_contents($file->getPathname())) as $class) {
            if (! in_array($class, $defined, true)) {
                $undefined[] = "{$path}: {$class}";
            }
        }
    }

    expect($scanned)->toBeGreaterThan(100)
        ->and($undefined)->toBe([], 'a type size app.css does not define draws at the inherited size: use one of '.implode(', ', $defined));
});
