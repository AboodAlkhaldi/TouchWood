<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * A label a store may put on a product (catalog.md §1.8, §4.4 S9 "Stores"): its names and its tone,
 * drawn as the shop shows it; only active labels are offered.
 */
final readonly class LabelChoice
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public string $tone,
        // Off since it was attached: shown where it is, never offered again.
        public bool $active = true,
    ) {}
}
