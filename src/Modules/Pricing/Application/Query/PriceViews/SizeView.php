<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PriceViews;

/**
 * **A size as Pricing's screens show it** (pricing.md §4.3.3 P1, P4, P5; Catalog's `searchVariants`): its
 * product's names, a photo (a media id: the page writes its address), its values in the page's language
 * ("60 cm", "Oak" - none for a product with one size: "One Size"), its code, and the marks a row draws -
 * archived (the size or its product: read-only until restored) and switched on in the store.
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
