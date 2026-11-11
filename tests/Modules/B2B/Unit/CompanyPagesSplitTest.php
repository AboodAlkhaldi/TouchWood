<?php

declare(strict_types=1);

/*
| The company page in two (owner, 2026-11-10: "ok split them into two pages"; b2b.md amendment 35):
| the view page (Company.tsx) never carries the form - the reason for the split, the 60 KB page
| budget (JavaScriptBudgetTest) - and the form's page (Edit.tsx) does. Read from the sources: every
| file a page imports, and every file those import, within resources/js.
*/

/**
 * Every source file under resources/js a page loads with it, itself included, following its
 * imports - `./`, `../` and `@/` -: static ones only, what the page needs before it shows, or
 * dynamic ones too.
 *
 * @return list<string> paths relative to resources/js
 */
function companyPagesSplitImports(string $page, bool $dynamicToo = true): array
{
    $pattern = $dynamicToo ? "/(?:from|import)\s*\(?\s*'([^']+)'/" : "/(?:from\s*|^import\s+)'([^']+)'/m";
    $root = dirname(__DIR__, 4).'/resources/js/';
    $seen = [];
    $queue = [$page];

    while ($queue !== []) {
        $file = array_shift($queue);

        if (isset($seen[$file])) {
            continue;
        }

        $seen[$file] = true;
        $source = (string) file_get_contents($root.$file);
        preg_match_all($pattern, $source, $matches);

        foreach ($matches[1] as $target) {
            $base = match (true) {
                str_starts_with($target, '@/') => substr($target, 2),
                str_starts_with($target, '.') => dirname($file).'/'.$target,
                default => null,
            };

            if ($base === null) {
                continue;
            }

            $base = companyPagesSplitNormal($base);

            foreach (['.tsx', '.ts', '/index.tsx', '/index.ts'] as $ending) {
                if (is_file($root.$base.$ending)) {
                    $queue[] = $base.$ending;
                    break;
                }
            }
        }
    }

    return array_keys($seen);
}

/** A path with its `.` and `..` resolved. */
function companyPagesSplitNormal(string $path): string
{
    $parts = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '.' && $part !== '') {
            $parts[] = $part;
        }
    }

    return implode('/', $parts);
}

it('keeps the form off the view page, and on the form\'s', function () {
    $view = companyPagesSplitImports('pages/B2B/Storefront/Company/Company.tsx');
    $edit = companyPagesSplitImports('pages/B2B/Storefront/Company/Edit.tsx');

    // The walk finds what the pages share, or it would pass over nothing.
    expect($view)->toContain('pages/B2B/Storefront/Company/CompanyShell.tsx', 'pages/B2B/Storefront/Company/fields.tsx', 'layouts/AccountLayout.tsx')
        ->and($view)->not->toContain('pages/B2B/Storefront/Company/CompanyForm.tsx')
        ->and($edit)->toContain('pages/B2B/Storefront/Company/CompanyForm.tsx', 'pages/B2B/Storefront/Company/CompanyShell.tsx');
});

it('loads the two confirmations on first press, on neither page before it shows', function () {
    $dialogs = ['pages/B2B/Storefront/Company/dialogs.tsx', 'components/ui/alert-dialog.tsx'];

    foreach (['Company', 'Edit'] as $name) {
        $page = "pages/B2B/Storefront/Company/{$name}.tsx";
        $static = companyPagesSplitImports($page, dynamicToo: false);

        // Both walks find the page's own parts; only the dynamic one reaches the dialogs.
        expect($static)->toContain('pages/B2B/Storefront/Company/CompanyShell.tsx')
            ->and(array_values(array_intersect($dialogs, $static)))->toBe([], "{$name}.tsx loads a confirmation before it shows")
            ->and(companyPagesSplitImports($page))->toContain(...$dialogs);
    }
});
