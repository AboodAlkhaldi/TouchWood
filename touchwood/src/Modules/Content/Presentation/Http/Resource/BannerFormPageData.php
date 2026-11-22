<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W3's form (content.md §1.6, §4.4): a new banner in a placement, or one that exists, its images as their boxes show them. */
#[TypeScript]
final class BannerFormPageData extends Data
{
    /**
     * @param  array<string, ContentImageData>  $images  slot => the image
     * @param  list<CategoryChoiceData>  $categories
     */
    public function __construct(
        public ContentStoreData $store,
        public string $storeTimezone,
        public string $placement,
        public ?BannerData $banner,
        public array $images,
        public array $categories,
        public bool $translationReady,
    ) {}
}
