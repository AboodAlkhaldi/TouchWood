<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * H3, the blog (content.md §1.12, §4.5) - or one of its categories: the published posts newest first, 12 then Show
 * More, and the categories holding one as chips, the one shown marked.
 */
#[TypeScript]
final class ShopBlogPageData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $posts
     * @param  list<array{name: string, slug: string}>  $categories
     */
    public function __construct(
        public array $posts,
        public ?string $next,
        public array $categories,
        public ?string $categorySlug,
        public ?string $categoryName,
        public ContentAddressData $address,
        public ContentHeadData $head,
    ) {}
}
