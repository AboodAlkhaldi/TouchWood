<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A product's page in a store (catalog.md §1.4). **Available** while a shopper can find it there —
 * listed, or left in an inactive category and so reachable; otherwise **one "Not available now"
 * page**: its name, photos and description, nothing to order, hidden from search engines. Never a
 * code (amendment 5(d)).
 */
final readonly class ProductPage
{
    /**
     * @param  array<string, mixed>  $description  the structured text (§1.1)
     * @param  list<array<string, array<string, string>>>  $photos  the gallery's ready photos, in order
     * @param  list<CardLabel>  $labels  none on a page that is not available
     * @param  list<ShopVariant>  $variants  those on sale here; none on a page that is not available
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $slug,
        public bool $available,
        public array $description,
        public array $photos,
        public array $labels,
        public array $variants,
    ) {}

    /** Kept out of search engines: a page with nothing to order (§1.4). */
    public function noindex(): bool
    {
        return ! $this->available;
    }
}
