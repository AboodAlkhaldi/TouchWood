<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use DateTimeImmutable;

/**
 * **One page of Catalog's in a store's sitemap** (catalog.md amendment 41(b); content.md §1.14, §2.3 row C3): a
 * category, a brand or a product listed there, its current address in each language it has one in, and when it
 * last changed. Paths are built by the caller: `/{store}/{locale}/categories/{slug}`, `brands/`, `products/`.
 */
final readonly class SitemapEntryDto
{
    public const string CATEGORY = 'category';

    public const string BRAND = 'brand';

    public const string PRODUCT = 'product';

    /**
     * @param  'category'|'brand'|'product'  $kind
     * @param  string|null  $slugAr  null where it has no Arabic page there
     * @param  string|null  $slugEn  null where it has no English page there
     */
    public function __construct(
        public string $kind,
        public string $id,
        public ?string $slugAr,
        public ?string $slugEn,
        public DateTimeImmutable $changedAt,
    ) {}
}
