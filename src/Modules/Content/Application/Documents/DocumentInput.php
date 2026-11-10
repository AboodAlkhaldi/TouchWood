<?php

declare(strict_types=1);

namespace Modules\Content\Application\Documents;

/**
 * What a page's, post's or project's form sends (content.md §4.4 W7-W9): a new one (`id` null, in `storeId`) or one
 * that exists, at the `version` the form read. Bodies are the editor's typed marks (§1.2); an address left empty is
 * made from its title; `imagesAr` / `imagesEn` give each `![...](image:N)` of the body its file, ref => media id.
 * What a kind does not have is left empty: a page's summary, a post's city.
 */
final readonly class DocumentInput
{
    /**
     * @param  array<int, string>  $imagesAr
     * @param  array<int, string>  $imagesEn
     * @param  list<string>  $products
     * @param  list<string>  $photos
     */
    public function __construct(
        public ?string $id,
        public ?string $storeId,
        public int $version,
        public ?string $titleAr,
        public ?string $titleEn,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?string $bodyAr = null,
        public ?string $bodyEn = null,
        public array $imagesAr = [],
        public array $imagesEn = [],
        public ?string $seoTitleAr = null,
        public ?string $seoTitleEn = null,
        public ?string $seoDescriptionAr = null,
        public ?string $seoDescriptionEn = null,
        public ?string $shareAr = null,
        public ?string $shareEn = null,
        public ?string $summaryAr = null,
        public ?string $summaryEn = null,
        public ?string $coverMediaId = null,
        public ?string $coverAltAr = null,
        public ?string $coverAltEn = null,
        public ?string $categoryId = null,
        public ?string $cityAr = null,
        public ?string $cityEn = null,
        public array $products = [],
        public array $photos = [],
    ) {}
}
