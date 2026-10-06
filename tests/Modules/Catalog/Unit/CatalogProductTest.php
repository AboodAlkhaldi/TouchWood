<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\ValueObject\ProductName;
use Modules\Catalog\Domain\ValueObject\ProductSlugs;
use Modules\Catalog\Public\Enums\ProductStage;

/*
| A product's stage as the model keeps it (catalog.md §4.1, amendment 3(m)): archived, it remembers
| the stage it left — a second archiving changes nothing — and restoring goes back there.
*/

function catalogProductDraft(): Product
{
    $name = ProductName::of('درج', 'Drawer');

    return Product::create('01j8z3k4m5n6p7q8r9s0t1v2w3', $name, ProductSlugs::for($name), '01j8z3k4m5n6p7q8r9s0t1v2w4');
}

it('remembers the stage it left, archived twice or not, and goes back there', function () {
    $draft = catalogProductDraft();
    $draft->archive();
    $draft->archive();
    $draft->restore();

    $ready = catalogProductDraft();
    $ready->markReady();
    $ready->archive();
    $ready->archive();

    expect($draft->stage())->toBe(ProductStage::Draft)
        ->and($draft->archivedFrom())->toBeNull()
        ->and($draft->hasBeenReady())->toBeFalse()
        ->and($ready->stage())->toBe(ProductStage::Archived)
        ->and($ready->archivedFrom())->toBe(ProductStage::Ready)
        ->and($ready->hasBeenReady())->toBeTrue();

    $ready->restore();

    expect($ready->stage())->toBe(ProductStage::Ready)
        ->and($ready->archivedFrom())->toBeNull();
});
