<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W7 · Pages, W8 · Blog's posts, W9 · Projects (content.md §4.4): one tab of the store's items (Published or Draft, each
 * tab with its count), last changed first, 50 at a time; for posts, the categories to filter by and the one chosen; for
 * projects, whether any is published (the page stays hidden till then, §1.13).
 */
#[TypeScript]
final class DocumentsPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  array<string, int>  $counts  by state
     * @param  list<DocumentRowData>  $rows
     * @param  list<BlogCategoryData>  $categories
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        public string $storeTimezone,
        public string $kind,
        public string $tab,
        public array $counts,
        public array $rows,
        public ?string $next,
        public array $categories,
        public ?string $category,
        public int $postsCount,
    ) {}
}
