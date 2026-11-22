<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A product's warranty on its page (catalog.md §4.5, §1.9): its name, its period — none meaning
 * lifetime — and its terms, the structured text.
 */
final readonly class ShopWarranty
{
    /**
     * @param  array<string, mixed>  $terms
     */
    public function __construct(
        public string $name,
        public ?int $periodMonths,
        public array $terms,
    ) {}
}
