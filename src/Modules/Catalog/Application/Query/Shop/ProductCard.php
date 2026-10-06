<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A product as a shopper's list shows it (catalog.md §5.4): its name and address in the page's
 * language, its card photo and the labels the store attached — **never its code** (amendment 5(d)).
 * The price joins it with stage 5.
 */
final readonly class ProductCard
{
    /**
     * @param  array<string, array<string, string>>|null  $photo  size slug => format => CDN URL
     * @param  list<CardLabel>  $labels  in the list's order
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $slug,
        public ?array $photo,
        public array $labels,
    ) {}
}
