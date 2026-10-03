<?php

declare(strict_types=1);

/*
| shadcn's code is used as its CLI writes it, with four edits and no others (frontend.md §1.11,
| owner 2026-10-02 and 2026-10-03). A reinstall writes upstream's file over ours and silently takes
| an edit back out, so each edit is checked here rather than remembered:
|
|   1. `accent` is TouchWood's copper; shadcn's hover, focus and open backgrounds read `muted`.
|   2. Sonner reads the theme from the server's page data, not from next-themes.
|   3. DirectionProvider (and shadcn's TooltipProvider) wrap the app, in both entries.
|   4. The sidebar's rail is placed with -end-4 / start-0, so it follows the sidebar in Arabic.
*/

const SHADCN_UI = 'resources/js/components/ui';

/**
 * The classes in a piece of code that paint with the bare `accent` token — TouchWood's copper —
 * leaving the sidebar's own `sidebar-accent` and the ink of `accent-foreground` alone.
 *
 * @return list<string>
 */
function copperClassesIn(string $code): array
{
    preg_match_all('/[^\s"\'`]+/', $code, $matches);

    return array_values(array_filter($matches[0], function (string $class): bool {
        $class = str_replace(['sidebar-accent', 'accent-foreground'], ['', ''], $class);

        return preg_match('/(^|[:\-\[(])accent($|[\/:\])])/', $class) === 1;
    }));
}

/**
 * The code without its comments: each edit's note names what it replaced ("upstream reads the theme
 * from next-themes"), and a note is not the code it describes.
 */
function withoutComments(string $code): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], $code);
}

function shadcnSource(string $relative): string
{
    $path = dirname(__DIR__, 2).'/'.$relative;

    expect(is_file($path))->toBeTrue("{$relative} is missing");

    return withoutComments((string) file_get_contents($path));
}

it('finds a copper background however it is written', function (string $code) {
    expect(copperClassesIn($code))->not->toBeEmpty();
})->with([
    'plain' => ['className="bg-accent"'],
    'on hover' => ['"hover:bg-accent hover:text-accent-foreground"'],
    'with an opacity' => ['dark:hover:bg-accent/50'],
    'in a state' => ['data-[state=open]:bg-accent'],
    'in an arbitrary selector' => ['[a&]:hover:bg-accent'],
]);

it('leaves the sidebar token and the ink alone', function () {
    expect(copperClassesIn('hover:bg-sidebar-accent focus:text-accent-foreground bg-muted'))->toBeEmpty();
});

it('reads code, not the notes beside it', function () {
    $code = "// Ours: upstream wrote `accent` here.\n/* also next-themes */\nconst a = \"bg-muted\" // and accent";

    expect(copperClassesIn(withoutComments($code)))->toBeEmpty()
        ->and(withoutComments($code))->not->toContain('next-themes')
        ->and(copperClassesIn(withoutComments('const a = "hover:bg-accent"')))->toBe(['hover:bg-accent']);
});

it('keeps copper out of shadcn\'s hover, focus and open states (edit 1)', function () {
    $root = dirname(__DIR__, 2);
    $files = glob($root.'/'.SHADCN_UI.'/*.tsx') ?: [];
    $offenders = [];

    foreach ($files as $file) {
        foreach (copperClassesIn(withoutComments((string) file_get_contents($file))) as $class) {
            $offenders[] = basename($file).': '.$class;
        }
    }

    expect($files)->not->toBeEmpty()
        ->and($offenders)->toBe([]);
});

it('lets the toasts read the theme the server chose (edit 2)', function () {
    $sonner = shadcnSource(SHADCN_UI.'/sonner.tsx');

    expect($sonner)->not->toContain('next-themes')
        ->and($sonner)->toContain('usePage<SharedProps>()')
        ->and($sonner)->toContain('theme={theme.mode}');

    $package = json_decode(shadcnSource('package.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($package['dependencies'] ?? []))->not->toContain('next-themes');
});

it('wraps the app in the direction and tooltip providers, in the browser and on the server (edit 3)', function (string $entry) {
    expect(shadcnSource($entry))->toContain('<Providers direction={firstDirection(props.initialPage.props)}>');
})->with(['resources/js/app.tsx', 'resources/js/ssr.tsx']);

it('gives the providers the page\'s direction', function () {
    $providers = shadcnSource('resources/js/components/Providers.tsx');

    expect($providers)->toContain('<DirectionProvider dir={dir}>')
        ->and($providers)->toContain('<TooltipProvider>')
        ->and($providers)->toContain("router.on('navigate'");
});

it('keeps the sidebar\'s rail on the sidebar\'s edge in Arabic (edit 4)', function () {
    $sidebar = shadcnSource(SHADCN_UI.'/sidebar.tsx');
    $rail = substr($sidebar, (int) strpos($sidebar, 'function SidebarRail'), 2500);

    expect($rail)->toContain('group-data-[side=left]:-end-4')
        ->and($rail)->toContain('group-data-[side=right]:start-0')
        ->and($rail)->not->toContain('group-data-[side=left]:-right-4')
        ->and($rail)->not->toContain('group-data-[side=right]:left-0');
});
