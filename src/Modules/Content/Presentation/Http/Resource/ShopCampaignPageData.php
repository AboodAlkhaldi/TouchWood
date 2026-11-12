<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * H7, a campaign's page while its run is live (content.md §4.5, Q5 (a)): its name and end, and the products the run
 * reaches as the shop's cards for this shopper, 24 then Show More.
 */
#[TypeScript]
final class ShopCampaignPageData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $cardFacts
     */
    public function __construct(
        public string $name,
        public string $endsAt,
        public array $products,
        public ?string $next,
        public array $cardFacts,
        public ContentAddressData $address,
        public ContentHeadData $head,
    ) {}
}
