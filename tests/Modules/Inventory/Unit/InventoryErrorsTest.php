<?php

declare(strict_types=1);

use Modules\Inventory\Domain\Exception\AlreadyHeld;
use Modules\Inventory\Domain\Exception\HoldNotEditable;
use Modules\Inventory\Domain\Exception\InventoryError;
use Modules\Inventory\Domain\Exception\NoHold;
use Modules\Inventory\Domain\Exception\NotEnoughStock;
use Modules\Inventory\Domain\Exception\NoteRequired;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Domain\Exception\ProviderOwnsStock;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\ShipMoreThanHeld;
use Modules\Inventory\Domain\Exception\StockBelowZero;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Exception\ThresholdInvalid;
use Shared\Domain\Error\ErrorCategory;

/*
| Inventory's errors (inventory.md §7), each with the type and the category the spec gives it - written
| out here, so a type renamed or a category changed (403 to 409, say) goes red - and a title and a
| detail in both languages with the same placeholders. Other modules match these types (§2.1).
*/

/**
 * @return array<class-string<InventoryError>, array{string, ErrorCategory}> §7, row by row
 */
function inventoryErrorTable(): array
{
    return [
        NotEnoughStock::class => ['inventory.not_enough_stock', ErrorCategory::Conflict],
        AlreadyHeld::class => ['inventory.already_held', ErrorCategory::Conflict],
        QuantityInvalid::class => ['inventory.quantity_invalid', ErrorCategory::Invalid],
        StockBelowZero::class => ['inventory.stock_below_zero', ErrorCategory::Conflict],
        NoteRequired::class => ['inventory.note_required', ErrorCategory::Invalid],
        ShipMoreThanHeld::class => ['inventory.ship_more_than_held', ErrorCategory::Conflict],
        ProviderOwnsStock::class => ['inventory.provider_owns_stock', ErrorCategory::Conflict],
        NotWired::class => ['inventory.not_wired', ErrorCategory::Conflict],
        ThresholdInvalid::class => ['inventory.threshold_invalid', ErrorCategory::Invalid],
        StoreOff::class => ['inventory.store_off', ErrorCategory::Forbidden],
        NoHold::class => ['inventory.no_hold', ErrorCategory::NotFound],
        HoldNotEditable::class => ['inventory.hold_not_editable', ErrorCategory::Conflict],
    ];
}

/**
 * @return list<class-string<InventoryError>> every concrete error in the folder
 */
function inventoryErrorClassesFound(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Inventory/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<InventoryError> $class */
        $class = 'Modules\\Inventory\\Domain\\Exception\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('has exactly the errors of §7 in its folder, no more and no fewer', function () {
    $table = array_keys(inventoryErrorTable());
    sort($table);

    // A guard over files must find something, or it passes over nothing.
    expect(inventoryErrorClassesFound())->toHaveCount(12)->toBe($table);
});

it('gives each error the type and the category of §7', function (InventoryError $error, string $type, ErrorCategory $category) {
    expect($error->type())->toBe($type)
        ->and($error->category())->toBe($category);
})->with(function (): array {
    $rows = [];

    foreach (inventoryErrorTable() as $class => [$type, $category]) {
        // Built without its constructor: the type and the category never depend on what it carries.
        $rows[$type] = [(new ReflectionClass($class))->newInstanceWithoutConstructor(), $type, $category];
    }

    return $rows;
});

it('has an Arabic and an English title and detail for every error, none empty, with the same placeholders', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Inventory/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Inventory/Presentation/lang/en/errors.php';
    $placeholders = static function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        $names = $found[0];
        sort($names);

        return $names;
    };

    foreach (inventoryErrorTable() as [$type]) {
        $key = substr($type, strlen('inventory.'));

        foreach (['title', 'detail'] as $part) {
            expect($ar[$key][$part] ?? '')->not->toBe('', "{$key}.{$part} has no Arabic")
                ->and($en[$key][$part] ?? '')->not->toBe('', "{$key}.{$part} has no English")
                ->and($placeholders($ar[$key][$part]))->toBe($placeholders($en[$key][$part]));
        }
    }

    expect(array_keys($ar['fields']))->toBe(array_keys($en['fields']));
});
