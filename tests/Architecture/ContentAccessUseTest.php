<?php

declare(strict_types=1);

/*
| Content → Access, for one purpose (content.md §9.4 A1, handoff §4.4): declaring its permissions. Deptrac
| lets Content's interior see all of Access's public surface and cannot say "these five classes only", so
| this test does, as CatalogAccessUseTest does for Catalog. A handler that reached for AccessApi would pass
| deptrac and fail here - and go to the owner first.
*/

/** The whole of what Content may take from Access. */
const CONTENT_ACCESS_ALLOWED = [
    'Modules\Access\Public\Contracts\PermissionCatalog',
    'Modules\Access\Public\Dto\PermissionDefinitionDto',
    'Modules\Access\Public\Enums\PermissionAudience',
    'Modules\Access\Public\Enums\PermissionGroup',
    'Modules\Access\Public\Enums\PermissionKind',
];

/**
 * @return array<string, list<string>> file => the Access names it imports or references
 */
function contentAccessReferences(): array
{
    $root = dirname(__DIR__, 2).'/src/Modules/Content';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());
        preg_match_all('/Modules\\\\Access\\\\[A-Za-z0-9_\\\\]+/', $code, $matches);

        if ($matches[0] !== []) {
            $found[str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname())] = array_values(array_unique($matches[0]));
        }
    }

    return $found;
}

it('finds Content using Access somewhere, so the guard is not passing over nothing', function () {
    expect(contentAccessReferences())->not->toBeEmpty();
});

it('takes from Access only what declaring permissions needs', function () {
    foreach (contentAccessReferences() as $file => $names) {
        foreach ($names as $name) {
            expect(in_array($name, CONTENT_ACCESS_ALLOWED, true))
                ->toBeTrue("{$file} uses {$name}; Content may use Access only to declare its permissions (content.md §9.4 A1). Anything more goes to the owner first.");
        }
    }
});

it('never lets Content\'s public surface see Access at all', function () {
    foreach (array_keys(contentAccessReferences()) as $file) {
        expect(str_starts_with(str_replace('\\', '/', $file), 'Public/'))->toBeFalse("{$file} is Content's public surface and may not reference Access.");
    }
});
