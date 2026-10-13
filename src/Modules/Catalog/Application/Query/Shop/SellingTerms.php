<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * How a product is sold in a store (catalog.md §1.3), as its page tells this viewer (P17): the
 * minimum and maximum a shopper may order, retail and wholesale. An individual is never told the
 * wholesale side (amendment 14(e)).
 */
final readonly class SellingTerms
{
    public function __construct(
        public int $retailMinimum,
        public ?int $retailMaximum,
        public ?int $wholesaleMinimum,
        public ?int $wholesaleMaximum,
    ) {}
}
