<?php

declare(strict_types=1);

/*
| What the audit log calls each Catalog action (Presentation/lang/{ar,en}/audit.php). An action with
| no name shows its raw key, so every one Catalog records is named in both languages.
*/

/**
 * Every action Catalog writes to the audit log, read from the code: each ListAudit call names its
 * subject, and what happened — added and deleted by the method itself, changed and replaced by name.
 *
 * @return list<string>
 */
function catalogAuditNamesRecorded(): array
{
    $actions = [];
    $root = dirname(__DIR__, 4).'/src/Modules/Catalog';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());
        preg_match_all("/ListAudit::(added|deleted)\\('([a-z_]+)'/", $code, $simple, PREG_SET_ORDER);
        preg_match_all("/ListAudit::(?:changed|replaced)\\('([a-z_]+)', '([a-z_]+)'/", $code, $changed, PREG_SET_ORDER);

        foreach ($simple as [, $what, $subject]) {
            $actions[] = "catalog.{$subject}.{$what}";
        }

        foreach ($changed as [, $subject, $what]) {
            $actions[] = "catalog.{$subject}.{$what}";
        }
    }

    $actions = array_values(array_unique($actions));
    sort($actions);

    return $actions;
}

it('finds the actions Catalog records', function () {
    // A guard over files must find something, or it passes over nothing.
    expect(count(catalogAuditNamesRecorded()))->toBeGreaterThanOrEqual(40);
});

it('names every action Catalog records, in Arabic and English', function () {
    foreach (catalogAuditNamesRecorded() as $action) {
        $key = 'catalog::audit.'.substr($action, strlen('catalog.'));

        foreach (['ar', 'en'] as $locale) {
            $name = trans($key, [], $locale);

            expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$action} has no {$locale} name");
        }
    }
});

it('names nothing the code does not record, the same in both languages', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Catalog/Presentation/lang/ar/audit.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Catalog/Presentation/lang/en/audit.php';
    $named = array_map(static fn (int|string $key): string => "catalog.{$key}", array_keys($en));
    sort($named);

    expect(array_keys($ar))->toBe(array_keys($en))
        ->and($named)->toBe(catalogAuditNamesRecorded());
});
