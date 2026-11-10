<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W3 · Banners (content.md §4.4, §1.6): one placement's banners in order, with each placement's count for its tab. */
#[TypeScript]
final class BannersPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  array<string, int>  $counts  placement => how many
     * @param  list<BannerRowData>  $banners
     * @param  list<CategoryChoiceData>  $categories
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        public string $storeTimezone,
        public string $placement,
        public array $counts,
        public array $banners,
        public array $categories,
    ) {}
}
