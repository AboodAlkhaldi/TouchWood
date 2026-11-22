<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * H2 and H4: a published page or blog post (content.md §1.11, §1.12, §4.5): its title and body's blocks (§1.2) with the
 * files they name, and a post's date, cover, summary, category and products as the shop's cards for this shopper, those
 * no longer sold left out. A project has its own page's data, `ShopProjectPageData`.
 */
#[TypeScript]
final class ShopDocumentPageData extends Data
{
    /**
     * @param  string  $kind  page or post
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<int, array{src: string|null, width: int|null, height: int|null}>  $images  by the block's `ref`
     * @param  array<string, array<string, string>>|null  $cover
     * @param  array{name: string, slug: string}|null  $category
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $cardFacts
     */
    public function __construct(
        public string $kind,
        public string $title,
        public array $blocks,
        public array $images,
        public ?string $publishedAt,
        public ?string $summary,
        public ?array $cover,
        public ?string $coverAlt,
        public ?array $category,
        public array $products,
        public array $cardFacts,
        public ContentAddressData $address,
        public ContentHeadData $head,
    ) {}
}
