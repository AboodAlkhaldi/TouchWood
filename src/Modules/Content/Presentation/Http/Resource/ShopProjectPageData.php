<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * H6, a published project's page (content.md §1.13, §4.5; flags-2026-11.md S4.1-S4.3): its title and story's blocks (§1.2)
 * with the files they name, its city, the client it was made with, its photos in order (every ready size, for the
 * gallery and its viewer) and the products used as the shop's cards for this shopper, those no longer sold left out.
 */
#[TypeScript]
final class ShopProjectPageData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<int, array{src: string|null, width: int|null, height: int|null}>  $images  by the block's `ref`
     * @param  array{name: string|null, logo: array<string, array<string, string>>|null, link: string|null}|null  $client  set when a name, a logo or a link is
     * @param  list<array<string, array<string, string>>>  $photos
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $cardFacts
     */
    public function __construct(
        public string $title,
        public array $blocks,
        public array $images,
        public ?string $city,
        public ?string $clientName,
        public ?array $client,
        public array $photos,
        public array $products,
        public array $cardFacts,
        public ContentAddressData $address,
        public ContentHeadData $head,
    ) {}
}
