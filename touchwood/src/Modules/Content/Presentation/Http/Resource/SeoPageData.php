<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W10 · SEO (content.md §4.4, §1.14): the store's defaults per language, its extra robots lines (one path a line), its
 * share image, and its sitemap - when it was last built, how many addresses it holds, its address, whether a rebuild
 * already waits.
 */
#[TypeScript]
final class SeoPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        public ?string $siteNameAr,
        public ?string $siteNameEn,
        public ?string $titlePatternAr,
        public ?string $titlePatternEn,
        public ?string $descriptionAr,
        public ?string $descriptionEn,
        public ?ContentImageData $shareImage,
        public string $robotsLines,
        public ?string $sitemapBuiltAt,
        public int $sitemapUrls,
        public string $sitemapAddress,
        public bool $rebuildWaits,
        public bool $translationReady,
    ) {}
}
