<?php

declare(strict_types=1);

/*
| shadcn's class merger knows Geist's type scale (frontend.md §1.10, §1.11: "Look";
| resources/js/lib/cn.ts).
|
| shadcn's components merge a page's classes with their own, and the later class of a kind wins.
| Told nothing, the merger read `text-label-12` as a colour: a green badge lost its green, and a
| button kept shadcn's `text-sm` beside Geist's size (found in batch A of the rebuild, 2026-10-03).
| Three things keep it right, and each is checked here: the bundler and the type checker point the
| bare `cn` import at our configured copy, and that copy's pattern covers every type class
| `resources/css/app.css` defines - so a new size cannot be added and forgotten.
*/

function classMergeFile(string $relative): string
{
    $path = dirname(__DIR__, 2).'/'.$relative;

    expect(is_file($path))->toBeTrue("{$relative} is missing");

    return (string) file_get_contents($path);
}

/**
 * The type scale's pattern in lib/cn.ts, as PHP reads a pattern.
 */
function classMergeTypePattern(): string
{
    if (preg_match('#const TYPE_SCALE = /(.+)/;#', classMergeFile('resources/js/lib/cn.ts'), $match) !== 1) {
        throw new RuntimeException('lib/cn.ts no longer declares TYPE_SCALE as one pattern.');
    }

    return '#'.$match[1].'#';
}

it('points the bundler at the configured merger, for the bare name only', function () {
    $vite = classMergeFile('vite.config.ts');

    expect($vite)->toContain('find: /^cn$/')
        ->and($vite)->toContain("new URL('./resources/js/lib/cn.ts', import.meta.url)");
});

it('points the type checker at the same file', function () {
    $paths = json_decode(classMergeFile('tsconfig.json'), true, flags: JSON_THROW_ON_ERROR)['compilerOptions']['paths'];

    expect($paths['cn'] ?? null)->toBe(['./resources/js/lib/cn.ts']);
});

it('reads every type class app.css defines as a font size', function () {
    preg_match_all('/@utility text-(heading|label|copy|button)-([a-z0-9-]+)/', classMergeFile('resources/css/app.css'), $matches, PREG_SET_ORDER);
    $sizes = array_map(static fn (array $match): string => "{$match[1]}-{$match[2]}", $matches);

    // The scale is there to check: heading, label, copy and button sizes, mono ones among them.
    expect(count($sizes))->toBeGreaterThan(20)
        ->and($sizes)->toContain('label-14-mono');

    foreach ($sizes as $size) {
        expect(preg_match(classMergeTypePattern(), $size))->toBe(1, "text-{$size} is not in lib/cn.ts's type scale");
    }
});

it('does not take a colour for a font size', function (string $colour) {
    expect(preg_match(classMergeTypePattern(), $colour))->toBe(0);
})->with(['ink', 'ink-muted', 'good', 'bad-soft', 'muted-foreground', 'current']);

/*
| Geist's materials: a material replaces the corners, the shadow and the background a shadcn part
| was given before it - a Card's rounded-xl and shadow-sm, a dialog's rounded-lg and shadow-lg -
| which otherwise came later in the stylesheet and won (the review of batch C, 2026-10-04).
*/

it('knows every material app.css defines, as replacing corners, shadow and background', function () {
    $cn = classMergeFile('resources/js/lib/cn.ts');
    preg_match_all('/@utility material-([a-z]+)/', classMergeFile('resources/css/app.css'), $defined);

    if (preg_match('/const MATERIALS = \[([^\]]*)\];/', $cn, $list) !== 1) {
        throw new RuntimeException('lib/cn.ts no longer declares MATERIALS as one list.');
    }

    preg_match_all("/'([a-z]+)'/", $list[1], $known);

    expect($defined[1])->not->toBeEmpty()
        ->and($known[1])->toEqualCanonicalizing($defined[1])
        ->and($cn)->toContain('material: [{ material: MATERIALS }]')
        ->and($cn)->toContain("material: ['rounded', 'shadow', 'bg-color']");
});
