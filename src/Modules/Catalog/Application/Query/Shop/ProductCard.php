<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A product as a shopper's list shows it (catalog.md §5.4): its name and address in the page's
 * language, its card photo and the labels the store attached — **never its code** (amendment 5(d)) —
 * and **the variant the card shows this viewer**: the first, in the product's own order, the viewer
 * can buy here (amendment 14(f)) — a retail one for an individual; companies and guests share a card
 * (§2.2). **"Last pieces"** while a size here this viewer can buy and order now is ending soon
 * (amendment 16(h)). **Its price** for this viewer (amendment 35), none until Pricing pushes one.
 *
 * The shop to the design (shop-design.md §4, §10.6): its brand's name and unit, how many sizes this viewer can order
 * now and, for one, what Add to cart adds; whether it sells wholesale; the next photos; and its rank and listing date,
 * which the page compares with the store's settings for Best seller and New arrival.
 */
final readonly class ProductCard
{
    /**
     * @param  array<string, array<string, string>>|null  $photo  size slug => format => CDN URL
     * @param  list<CardLabel>  $labels  in the list's order
     * @param  array{variant: string, mode: string, minimum: int}|null  $oneSize
     * @param  list<array<string, array<string, string>>>  $morePhotos
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $slug,
        public ?array $photo,
        public array $labels,
        public string $variantId,
        public bool $lastPieces = false,
        public ?CardRating $rating = null,
        public ?CardPrice $price = null,
        public ?string $brand = null,
        public string $unit = 'PIECE',
        public int $sizes = 0,
        public ?array $oneSize = null,
        public bool $sellsWholesale = false,
        public array $morePhotos = [],
        public ?int $salesRank = null,
        public ?string $listedAt = null,
    ) {}
}
