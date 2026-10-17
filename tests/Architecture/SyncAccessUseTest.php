<?php

declare(strict_types=1);

/*
| Sync → Access, for one purpose (owner, 2026-10-07; handoff §4.4, sync.md §2.3): declaring its
| permissions. Deptrac lets Sync's interior see all of Access's public surface and cannot say "these
| five classes only", so this test does - as InventoryAccessUseTest does for Inventory. A handler
| that reached for AccessApi would pass deptrac and fail here, and go to the owner first.
*/

/** The whole of what Sync may take from Access. */
const SYNC_ACCESS_ALLOWED = [
    'Modules\Access\Public\Contracts\PermissionCatalog',
    'Modules\Access\Public\Dto\PermissionDefinitionDto',
    'Modules\Access\Public\Enums\PermissionAudience',
    'Modules\Access\Public\Enums\PermissionGroup',
    'Modules\Access\Public\Enums\PermissionKind',
];

/**
 * @return array<string, list<string>> file => the Access names it imports or references
 */
function syncAccessReferences(): array
{
    $root = dirname(__DIR__, 2).'/src/Modules/Sync';
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

it('finds Sync using Access somewhere, so the guard is not passing over nothing', function () {
    expect(syncAccessReferences())->not->toBeEmpty();
});

it('takes from Access only what declaring its permissions needs', function () {
    foreach (syncAccessReferences() as $file => $names) {
        foreach ($names as $name) {
            expect(in_array($name, SYNC_ACCESS_ALLOWED, true))
                ->toBeTrue("{$file} uses {$name}; Sync may use Access only to declare its permissions (handoff §4.4). Anything more goes to the owner first.");
        }
    }
});

it('never lets Sync\'s public surface see Access at all', function () {
    foreach (array_keys(syncAccessReferences()) as $file) {
        expect(str_starts_with(str_replace('\\', '/', $file), 'Public/'))->toBeFalse("{$file} is Sync's public surface and may not reference Access.");
    }
});
