<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W8 · Blog, its Categories tab (content.md §4.4, §1.12): the store's categories in their order, each with its posts. */
#[TypeScript]
final class BlogCategoriesPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  list<BlogCategoryData>  $categories
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        public array $categories,
        public int $postsCount,
        public bool $translationReady,
    ) {}
}
