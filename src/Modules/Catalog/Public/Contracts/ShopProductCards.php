<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * **Chosen products as the shop's cards** (catalog.md amendment 46(c)), for a page another module draws - a blog post's
 * or a project's linked products, a campaign's page (content.md §1.12, §1.13, H7). The cards the shop's lists draw
 * (`ShopCardData`: prices, labels, stars), in the order given, **only those the shopper looking can order here now**:
 * a product no longer sold, sold out or hidden from them is left out, so it vanishes from the page. With `cardFacts`,
 * what the modules add to them (the Save heart), as on every list page. As `ShopBestSellers`, ready for the page.
 */
interface ShopProductCards
{
    /**
     * A language but Arabic or English is refused, as on the shop's own pages.
     *
     * @param  list<string>  $productIds  at most 100; more are left out
     * @return array{cards: list<array<string, mixed>>, cardFacts: array<string, array<string, array<string, mixed>>>}
     */
    public function read(StoreId $store, string $locale, array $productIds): array;
}
