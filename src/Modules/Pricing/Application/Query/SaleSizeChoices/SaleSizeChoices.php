<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\SaleSizeChoices;

/**
 * **A product's sizes for the offer dialog's Sizes** (pricing.md §4.3.3 P2, Q39): each size switched on in
 * the store, with its retail price there - a size with none is listed and can't be chosen.
 */
final readonly class SaleSizeChoices
{
    public function __construct(
        public string $storeId,
        public string $productId,
    ) {}
}
