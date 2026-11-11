<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W7-W9's form (content.md §4.4): a new page, post or project in the store, or one that exists, with every file it shows
 * as its boxes show them, the products it links by name, a post's categories, and the shop's address its own is drawn
 * under (`{shopAddress}/{locale}{prefix}{slug}`), and where Content's own links name it - Unpublish and Delete say so first
 * (§1.1 rule 16), by place with a count.
 */
#[TypeScript]
final class DocumentFormPageData extends Data
{
    /**
     * @param  array<string, ContentImageData>  $images  by media id
     * @param  list<ProductChoiceData>  $products
     * @param  list<BlogCategoryData>  $categories
     * @param  array<string, int>  $linkedFrom  place => how many links
     */
    public function __construct(
        public ContentStoreData $store,
        public string $kind,
        public ?DocumentData $document,
        public array $images,
        public array $products,
        public array $categories,
        public string $shopAddress,
        public string $pathPrefix,
        public bool $translationReady,
        public array $linkedFrom,
    ) {}
}
