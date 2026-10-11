<?php

declare(strict_types=1);

/*
| Inventory → Access, for one purpose (owner, 2026-10-07; handoff §4.4, inventory.md §2.4): declaring
| its permission. Deptrac lets Inventory's interior see all of Access's public surface and cannot say
| "these five classes only", so this test does - as CatalogAccessUseTest does for Catalog. A handler
| that reached for AccessApi would pass deptrac and fail here, and go to the owner first.
*/

/** The whole of what Inventory may take from Access. */
const INVENTORY_ACCESS_ALLOWED = [
    'Modules\Access\Public\Contracts\PermissionCatalog',
    'Modules\Access\Public\Dto\PermissionDefinitionDto',
    'Modules\Access\Public\Enums\PermissionAudience',
    'Modules\Access\Public\Enums\PermissionGroup',
    'Modules\Access\Public\Enums\PermissionKind',
];

/**
 * @return array<string, list<string>> file => the Access names it imports or references
 */
function inventoryAccessReferences(): array
{
    $root = dirname(__DIR__, 2).'/src/Modules/Inventory';
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

it('finds Inventory using Access somewhere, so the guard is not passing over nothing', function () {
    expect(inventoryAccessReferences())->not->toBeEmpty();
});

it('takes from Access only what declaring its permission needs', function () {
    foreach (inventoryAccessReferences() as $file => $names) {
        foreach ($names as $name) {
            expect(in_array($name, INVENTORY_ACCESS_ALLOWED, true))
                ->toBeTrue("{$file} uses {$name}; Inventory may use Access only to declare its permission (handoff §4.4). Anything more goes to the owner first.");
        }
    }
});

it('never lets Inventory\'s public surface see Access at all', function () {
    foreach (array_keys(inventoryAccessReferences()) as $file) {
        expect(str_starts_with(str_replace('\\', '/', $file), 'Public/'))->toBeFalse("{$file} is Inventory's public surface and may not reference Access.");
    }
});
