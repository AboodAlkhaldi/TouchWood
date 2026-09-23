<?php

declare(strict_types=1);

/*
| A theme is data, not code (frontend.md §1.8, owner 2026-09-22).
|
| The owner intends campaign themes — the whole system dressed for National Day, Ramadan, a sale —
| each one a named set of values. That is only possible while no component carries a colour of its
| own: one hard-coded navy in one button is a button that stays navy through every campaign, and
| nobody finds it until the campaign is live.
|
| So: colours live in resources/css/themes.css and nowhere else.
*/

/** The one file allowed to hold colour values, plus the file that maps them to Tailwind's names. */
const THEME_FILES = ['resources/css/themes.css', 'resources/css/app.css'];

/**
 * Whether a file may contain a colour value. Named so the rule itself can be tested rather than
 * only applied — a guard whose rule is wrong passes over everything.
 */
function mayHoldColours(string $path): bool
{
    return in_array($path, THEME_FILES, true);
}

/**
 * Colour values written directly: #0b3b63, rgb(...), rgba(...), hsl(...), oklch(...).
 *
 * @return list<string>
 */
function colourLiteralsIn(string $code): array
{
    $found = [];

    foreach (['/#[0-9a-fA-F]{3,8}\b/', '/\b(rgb|rgba|hsl|hsla|oklch|lab)\s*\(/'] as $pattern) {
        if (preg_match_all($pattern, $code, $matches) > 0) {
            array_push($found, ...$matches[0]);
        }
    }

    return $found;
}

it('detects a colour written into code', function (string $code) {
    expect(colourLiteralsIn($code))->not->toBeEmpty();
})->with([
    'a hex colour' => ['background: #0B3B63;'],
    'a short hex colour' => ['color: #fff'],
    'a hex colour in a class' => ['className="bg-[#0B3B63]"'],
    'rgb' => ['border: 1px solid rgb(11, 59, 99)'],
    'rgba with spaces' => ['box-shadow: 0 0 0 rgba (0,0,0,.4)'],
    'oklch' => ['--x: oklch(0.7 0.1 250)'],
]);

it('ignores code that names a token instead of a colour', function () {
    expect(colourLiteralsIn('className="bg-brand text-ink-muted rounded-lg shadow-card"'))->toBeEmpty();
});

it('keeps every colour in the theme files, so a campaign theme is data', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    $scanned = 0;

    foreach ([$root.'/resources/js', $root.'/resources/css'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['ts', 'tsx', 'css'], true)) {
                continue;
            }

            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (mayHoldColours($path)) {
                continue;
            }

            $scanned++;
            $literals = colourLiteralsIn((string) file_get_contents($file->getPathname()));

            if ($literals !== []) {
                $offenders[] = $path.': '.implode(', ', array_unique($literals));
            }
        }
    }

    // A moved directory must not make this pass over nothing.
    expect($scanned)->toBeGreaterThan(15)
        ->and($offenders)->toBe([]);
});

it('allows colours in the theme files and nowhere else', function (string $path, bool $allowed) {
    expect(mayHoldColours($path))->toBe($allowed);
})->with([
    'the themes file' => ['resources/css/themes.css', true],
    'the token map' => ['resources/css/app.css', true],
    'a page' => ['resources/js/pages/Access/Admin/SignIn.tsx', false],
    'a layout' => ['resources/js/layouts/AdminLayout.tsx', false],
    'a shadcn component' => ['resources/js/components/ui/button.tsx', false],
    'another css file' => ['resources/css/print.css', false],
]);

it('gives every mode and campaign the same tokens, so none can forget one', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/themes.css');

    // Comments go first: they explain the shape of a campaign by showing one, braces and all, and
    // a parser that cannot tell an example from a rule reads the whole preamble as a selector.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    // Each block is "selector { ... }" at the top level of the file.
    preg_match_all('/(?<selector>[^{}]+)\{(?<body>[^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER);

    $themes = [];

    foreach ($blocks as $block) {
        preg_match_all('/--tw-[a-z0-9-]+/', $block['body'], $names);
        $themes[trim($block['selector'])] = array_unique($names[0]);
    }

    expect(count($themes))->toBeGreaterThan(1);

    $first = array_key_first($themes);
    $expected = $themes[$first];
    sort($expected);

    foreach ($themes as $selector => $tokens) {
        sort($tokens);

        // A block missing a token silently inherits another's value, which is how a campaign ends
        // up with one wrong button that nobody can explain.
        expect($tokens)->toBe($expected, "The block \"{$selector}\" does not define the same tokens as \"{$first}\".");
    }
});
