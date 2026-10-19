<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\StockViews;

/**
 * **A size as Inventory's screens show it** (inventory.md §4.3.3; Catalog's `searchVariants`): its
 * product's names, a photo (a media id: the page writes its address), its values in the page's language
 * ("60 cm", "Oak" - none for a product with one size: "One Size"), its code, and the marks a row draws -
 * **Archived** (the size or its product: read-only until restored, §4.3.1) and switched on in the store
 * (a size stocked though switched off - one given only as a gift - is listed too, §1.7).
 */
final readonly class SizeView
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public ?string $photoMediaId,
        public string $code,
        public array $values,
        public bool $archived,
        public bool $switchedOn,
    ) {}
}
