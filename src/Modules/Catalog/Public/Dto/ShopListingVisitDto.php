<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Shared\Domain\ValueObject\StoreId;

/**
 * A product list being drawn in the shop, as the modules above it are told (catalog.md amendment 41(a)): which store
 * and language, which kind of list, and for a category's list the category and those above it.
 */
final readonly class ShopListingVisitDto
{
    public const string CATEGORY = 'category';

    public const string BRAND = 'brand';

    public const string SEARCH = 'search';

    /**
     * @param  string  $kind  `category`, `brand` or `search`
     * @param  string|null  $categoryId  the category whose list it is; null for a brand's or a search's
     * @param  list<string>  $categoryPath  that category and those above it, from the top; empty for a brand's or a search's
     */
    public function __construct(
        public StoreId $store,
        public string $locale,
        public string $kind,
        public ?string $categoryId = null,
        public array $categoryPath = [],
    ) {}
}
