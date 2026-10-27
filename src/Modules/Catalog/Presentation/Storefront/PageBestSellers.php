<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Storefront;

use Modules\Catalog\Public\Contracts\ShopBestSellers;
use Shared\Domain\ValueObject\StoreId;

/** `ShopBestSellers` as the shop's own pages draw cards (amendment 36). */
final readonly class PageBestSellers implements ShopBestSellers
{
    public function __construct(
        private ShopPages $pages,
    ) {}

    public function read(StoreId $store, string $locale, int $limit): array
    {
        return $this->pages->bestSelling($store, $locale, $limit);
    }
}
