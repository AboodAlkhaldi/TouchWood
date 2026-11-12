<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * H1, a store's home (content.md §1.4, §4.5): the shown sections in their order with their words in the page's
 * language, the live home banners, and what is read per request - this shopper's best sellers (with what the modules add
 * to the cards), the top categories as tiles, and the Points figures from the store's settings (Q16 (b)), null while
 * the programme is off.
 */
#[TypeScript]
final class ShopHomePageData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>|null  $heroBanner
     * @param  list<array<string, mixed>>  $phoneBanners
     * @param  list<array<string, mixed>>  $bestSellers
     * @param  array<string, mixed>  $cardFacts
     * @param  list<array<string, mixed>>  $categoryTiles
     * @param  array{earn: int, off: int, currency: string}|null  $points
     * @param  array{promises: array<string, string>, promisesArt: bool, pointsHeading: string|null, pointsArt: bool, logosHeading: string|null, logosArt: bool}  $themeParts  the live theme's home parts (§1.8 parts 3-5)
     */
    public function __construct(
        public array $sections,
        public ?array $heroBanner,
        public array $phoneBanners,
        public array $bestSellers,
        public array $cardFacts,
        public array $categoryTiles,
        public ?array $points,
        public array $themeParts,
        public ContentAddressData $address,
        public ContentHeadData $head,
    ) {}
}
