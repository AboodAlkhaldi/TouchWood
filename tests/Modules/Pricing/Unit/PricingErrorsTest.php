<?php

declare(strict_types=1);

use Modules\Pricing\Domain\Exception\AlwaysWinsOverlap;
use Modules\Pricing\Domain\Exception\AmountsInvalid;
use Modules\Pricing\Domain\Exception\BandsInvalid;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\CategoryNotUsable;
use Modules\Pricing\Domain\Exception\DuplicateLines;
use Modules\Pricing\Domain\Exception\LinesWithoutPrice;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\NotSoldWholesale;
use Modules\Pricing\Domain\Exception\PercentOutOfRange;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\Exception\PricingError;
use Modules\Pricing\Domain\Exception\ProviderOwnsPrice;
use Modules\Pricing\Domain\Exception\SaleNotBelowRetail;
use Modules\Pricing\Domain\Exception\SaleNotFound;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Shared\Domain\Error\ErrorCategory;

/*
| Pricing's errors (pricing.md §7), each with the type and the category the spec gives it - written
| out here, so a type renamed or a category changed (403 to 409, say) goes red - and a title and a
| detail in both languages with the same placeholders. The three the contract names (§2.1) are matched
| by other modules; §7 gives them "INVALID / CONFLICT", settled by the owner on 2026-10-10: duplicate
| lines and invalid amounts INVALID, lines without a price CONFLICT.
*/

/**
 * @return array<class-string<PricingError>, array{string, ErrorCategory}> §7, row by row
 */
function pricingErrorTable(): array
{
    return [
        PriceNotPositive::class => ['pricing.price_not_positive', ErrorCategory::Invalid],
        PriceTooPrecise::class => ['pricing.price_too_precise', ErrorCategory::Invalid],
        PercentOutOfRange::class => ['pricing.percent_out_of_range', ErrorCategory::Invalid],
        SaleNotBelowRetail::class => ['pricing.sale_not_below_retail', ErrorCategory::Invalid],
        WindowInvalid::class => ['pricing.window_invalid', ErrorCategory::Invalid],
        SaleNotFound::class => ['pricing.sale_not_found', ErrorCategory::NotFound],
        CategoryDiscountNotFound::class => ['pricing.category_discount_not_found', ErrorCategory::NotFound],
        NotChangeable::class => ['pricing.not_changeable', ErrorCategory::Conflict],
        AlwaysWinsOverlap::class => ['pricing.always_wins_overlap', ErrorCategory::Conflict],
        BandsInvalid::class => ['pricing.bands_invalid', ErrorCategory::Invalid],
        NotSoldWholesale::class => ['pricing.not_sold_wholesale', ErrorCategory::Conflict],
        CategoryNotUsable::class => ['pricing.category_not_usable', ErrorCategory::Invalid],
        NoRetailPrice::class => ['pricing.no_retail_price', ErrorCategory::Conflict],
        ProviderOwnsPrice::class => ['pricing.provider_owns_price', ErrorCategory::Conflict],
        StoreOff::class => ['pricing.store_off', ErrorCategory::Forbidden],
        DuplicateLines::class => ['pricing.duplicate_lines', ErrorCategory::Invalid],
        LinesWithoutPrice::class => ['pricing.lines_without_price', ErrorCategory::Conflict],
        AmountsInvalid::class => ['pricing.amounts_invalid', ErrorCategory::Invalid],
    ];
}

/**
 * @return list<class-string<PricingError>> every concrete error in the folder
 */
function pricingErrorClassesFound(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Pricing/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<PricingError> $class */
        $class = 'Modules\\Pricing\\Domain\\Exception\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('has exactly the errors of §7 in its folder, no more and no fewer', function () {
    $table = array_keys(pricingErrorTable());
    sort($table);

    // A guard over files must find something, or it passes over nothing.
    expect(pricingErrorClassesFound())->toHaveCount(18)->toBe($table);
});

it('gives each error the type and the category of §7', function (PricingError $error, string $type, ErrorCategory $category) {
    expect($error->type())->toBe($type)
        ->and($error->category())->toBe($category);
})->with(function (): array {
    $rows = [];

    foreach (pricingErrorTable() as $class => [$type, $category]) {
        // Built without its constructor: the type and the category never depend on what it carries.
        $rows[$type] = [(new ReflectionClass($class))->newInstanceWithoutConstructor(), $type, $category];
    }

    return $rows;
});

it('has an Arabic and an English title and detail for every error, none empty, with the same placeholders', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/en/errors.php';
    $placeholders = static function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        $names = $found[0];
        sort($names);

        return $names;
    };

    foreach (pricingErrorTable() as [$type]) {
        $key = substr($type, strlen('pricing.'));

        foreach (['title', 'detail'] as $part) {
            expect($ar[$key][$part] ?? '')->not->toBe('', "{$key}.{$part} has no Arabic")
                ->and($en[$key][$part] ?? '')->not->toBe('', "{$key}.{$part} has no English")
                ->and($placeholders($ar[$key][$part]))->toBe($placeholders($en[$key][$part]));
        }
    }

    // The one message that names a value names it in both languages.
    expect($placeholders($en['price_too_precise']['detail']))->toBe([':decimals'])
        ->and(array_keys($ar['fields']))->toBe(array_keys($en['fields']));
});

it('carries the decimals a too-precise price was refused for, for the message', function () {
    expect((new PriceTooPrecise(2))->context())->toBe(['decimals' => 2]);
});
