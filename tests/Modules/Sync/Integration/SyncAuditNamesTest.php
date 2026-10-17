<?php

declare(strict_types=1);

/*
| What the audit log calls each Sync action (Presentation/lang/{ar,en}/audit.php; sync.md §3). An action
| with no name shows its raw key, so every one Sync records is named in both languages.
*/

/**
 * Every action Sync writes to the audit log, read from the code: each entry is built with its action
 * written out first.
 *
 * @return list<string>
 */
function syncAuditNamesRecorded(): array
{
    $actions = [];
    $root = dirname(__DIR__, 4).'/src/Modules/Sync';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/new AuditEntryDto\\(\\s*'(sync\\.[a-z_]+\\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $found);
        array_push($actions, ...$found[1]);
    }

    $actions = array_values(array_unique($actions));
    sort($actions);

    return $actions;
}

it('finds the actions Sync records', function () {
    // A guard over files must find something, or it passes over nothing.
    expect(syncAuditNamesRecorded())->toBe(['sync.connection.changed', 'sync.connection.unwired', 'sync.connection.wired', 'sync.read.done', 'sync.read.failed']);
});

it('names every action Sync records, in Arabic and English', function () {
    foreach (syncAuditNamesRecorded() as $action) {
        $key = 'sync::audit.'.substr($action, strlen('sync.'));

        foreach (['ar', 'en'] as $locale) {
            $name = trans($key, [], $locale);

            expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$action} has no {$locale} name");
        }
    }
});

it('names nothing the code does not record, the same in both languages', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Sync/Presentation/lang/ar/audit.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Sync/Presentation/lang/en/audit.php';
    $named = array_map(static fn (int|string $key): string => "sync.{$key}", array_keys($en));
    sort($named);

    expect(array_keys($ar))->toBe(array_keys($en))
        ->and($named)->toBe(syncAuditNamesRecorded());
});
