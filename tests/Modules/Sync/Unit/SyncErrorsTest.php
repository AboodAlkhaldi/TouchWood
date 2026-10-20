<?php

declare(strict_types=1);

use Modules\Sync\Domain\Exception\AlreadyWired;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\EntryNotDismissable;
use Modules\Sync\Domain\Exception\EntryNotFound;
use Modules\Sync\Domain\Exception\EntryNotRetryable;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\Exception\ReadNotFound;
use Modules\Sync\Domain\Exception\SizeNotFound;
use Modules\Sync\Domain\Exception\StoreOff;
use Modules\Sync\Domain\Exception\SyncError;
use Shared\Domain\Error\ErrorCategory;

/*
| Sync's errors (sync.md §7), each with the type and the category the spec gives it - written out here,
| so a type renamed or a category changed goes red - and a title and a detail in both languages with
| the same placeholders. ReadRunning is in §7 only to say it is never thrown (a second read waits).
*/

/**
 * @return array<class-string<SyncError>, array{string, ErrorCategory}> §7, row by row
 */
function syncErrorTable(): array
{
    return [
        ConnectionFailed::class => ['sync.connection_failed', ErrorCategory::Invalid],
        AlreadyWired::class => ['sync.already_wired', ErrorCategory::Conflict],
        NotWired::class => ['sync.not_wired', ErrorCategory::Conflict],
        EntryNotFound::class => ['sync.entry_not_found', ErrorCategory::NotFound],
        InvalidSyncInput::class => ['sync.invalid_sync_input', ErrorCategory::Invalid],
        ReadNotFound::class => ['sync.read_not_found', ErrorCategory::NotFound],
        SizeNotFound::class => ['sync.size_not_found', ErrorCategory::NotFound],
        EntryNotDismissable::class => ['sync.entry_not_dismissable', ErrorCategory::Conflict],
        EntryNotRetryable::class => ['sync.entry_not_retryable', ErrorCategory::Conflict],
        StoreOff::class => ['sync.store_off', ErrorCategory::Forbidden],
    ];
}

/**
 * @return list<class-string<SyncError>> every concrete error in the folder
 */
function syncErrorClassesFound(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Sync/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<SyncError> $class */
        $class = 'Modules\\Sync\\Domain\\Exception\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('has exactly the errors of §7 in its folder, no more and no fewer', function () {
    $table = array_keys(syncErrorTable());
    sort($table);

    // A guard over files must find something, or it passes over nothing.
    expect(syncErrorClassesFound())->toHaveCount(10)->toBe($table);
});

it('gives each error the type and the category of §7', function (SyncError $error, string $type, ErrorCategory $category) {
    expect($error->type())->toBe($type)
        ->and($error->category())->toBe($category);
})->with(function (): array {
    $rows = [];

    foreach (syncErrorTable() as $class => [$type, $category]) {
        // Built without its constructor: the type and the category never depend on what it carries.
        $rows[$type] = [(new ReflectionClass($class))->newInstanceWithoutConstructor(), $type, $category];
    }

    return $rows;
});

it('has an Arabic and an English title and detail for every error, none empty, with the same placeholders', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Sync/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Sync/Presentation/lang/en/errors.php';
    $placeholders = static function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        $names = $found[0];
        sort($names);

        return $names;
    };

    foreach (syncErrorTable() as [$type]) {
        $key = substr($type, strlen('sync.'));

        foreach (['title', 'detail'] as $part) {
            expect($ar[$key][$part] ?? '')->not->toBe('', "{$key}.{$part} has no Arabic")
                ->and($en[$key][$part] ?? '')->not->toBe('', "{$key}.{$part} has no English")
                ->and($placeholders($ar[$key][$part]))->toBe($placeholders($en[$key][$part]));
        }
    }

    // Every field an invalid input names has its name in both languages (§7: store, address, key,
    // secret, provider; the screens' list place and read section; a provider's setting, by its own name, Q34).
    expect($placeholders($en['invalid_sync_input']['detail']))->toBe([':attribute'])
        ->and($placeholders($en['invalid_sync_input']['rules']['setting']))->toBe([':setting'])
        ->and($placeholders($ar['invalid_sync_input']['rules']['setting']))->toBe([':setting'])
        // The address, by each rule it may break, in both languages (§2.4).
        ->and(array_keys($en['invalid_sync_input']['rules']))->toBe(['setting', 'address_https', 'address_query', 'address_path'])
        ->and(array_keys($ar['invalid_sync_input']['rules']))->toBe(array_keys($en['invalid_sync_input']['rules']))
        ->and(array_keys($en['fields']))->toBe(['address', 'after', 'key', 'kind', 'provider', 'secret', 'section', 'settings', 'store'])
        ->and(array_keys($ar['fields']))->toBe(array_keys($en['fields']));
});

it('carries the field an invalid input names, and the reason a test read failed, for the message', function () {
    expect((new InvalidSyncInput('address'))->context())->toBe(['attribute' => 'address'])
        ->and((new InvalidSyncInput('settings', 'database'))->context())->toBe(['attribute' => 'settings', 'setting' => 'database', 'rule' => 'setting'])
        ->and((new InvalidSyncInput('address', rule: InvalidSyncInput::ADDRESS_PATH))->context())->toBe(['attribute' => 'address', 'rule' => 'address_path'])
        ->and((new ConnectionFailed('Timed out'))->context())->toBe(['reason' => 'Timed out']);
});
