<?php

declare(strict_types=1);

/*
| What the audit log calls each Inventory action (Presentation/lang/{ar,en}/audit.php; inventory.md §3,
| §8 #12). An action with no name shows its raw key, so every one Inventory records is named in both
| languages.
*/

/**
 * Every action Inventory writes to the audit log, read from the code: each entry is built with its
 * action written out first.
 *
 * @return list<string>
 */
function inventoryAuditNamesRecorded(): array
{
    $actions = [];
    $root = dirname(__DIR__, 4).'/src/Modules/Inventory';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/new AuditEntryDto\\(\\s*'(inventory\\.[a-z_]+\\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $found);
        // Every action names its subject's resource first ("{module}.{resource}.{past-tense}", AuditEntryDto).
        preg_match_all("/new AuditEntryDto\\(\\s*'inventory\\.([a-z_]+)\\.[a-z_]+',\\s*'inventory\\.([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $pairs, PREG_SET_ORDER);

        expect(count($pairs))->toBe(count($found[1]), "{$file->getFilename()} names a subject this check cannot read");

        foreach ($pairs as [, $resource, $subject]) {
            expect($resource)->toBe($subject);
        }
        array_push($actions, ...$found[1]);
    }

    $actions = array_values(array_unique($actions));
    sort($actions);

    return $actions;
}

it('finds the actions Inventory records', function () {
    // A guard over files must find something, or it passes over nothing.
    expect(inventoryAuditNamesRecorded())->toBe([
        'inventory.stock.adjusted',
        'inventory.stock.counted',
        'inventory.stock.stock_dependent_set',
        'inventory.stock.threshold_set',
        'inventory.store_product.ending_soon_set',
    ]);
});

it('names every action Inventory records, in Arabic and English', function () {
    foreach (inventoryAuditNamesRecorded() as $action) {
        $key = 'inventory::audit.'.substr($action, strlen('inventory.'));

        foreach (['ar', 'en'] as $locale) {
            $name = trans($key, [], $locale);

            expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$action} has no {$locale} name");
        }
    }
});

it('names nothing the code does not record, the same in both languages', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Inventory/Presentation/lang/ar/audit.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Inventory/Presentation/lang/en/audit.php';
    $named = array_map(static fn (int|string $key): string => "inventory.{$key}", array_keys($en));
    sort($named);

    expect(array_keys($ar))->toBe(array_keys($en))
        ->and($named)->toBe(inventoryAuditNamesRecorded());
});
