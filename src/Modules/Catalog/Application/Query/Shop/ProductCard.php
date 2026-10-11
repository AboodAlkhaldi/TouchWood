<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A product as a shopper's list shows it (catalog.md §5.4): its name and address in the page's
 * language, its card photo and the labels the store attached — **never its code** (amendment 5(d)) —
 * and **the variant the card shows this viewer**: the first, in the product's own order, the viewer
 * can buy here (amendment 14(f)) — a retail one for an individual, and for a guest where only
 * individuals see the product (P25). Its price joins it with the shop's pages (§2.2, P22).
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
        public string $variantId,
    ) {}
}
