<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A run's prices as Pricing's preview reads them, live as they are typed (content.md §1.7 #6): the totals, the
 * products 20 at a time, the deepest reduction and the other offers.
 */
#[TypeScript]
final class CampaignPreviewData extends Data
{
    /**
     * @param  list<CampaignPreviewProductData>  $products
     * @param  list<CampaignOfferData>  $otherOffers
     */
    public function __construct(
        public array $products,
        public ?string $next,
        public int $reachedProducts,
        public int $reachedSizes,
        public int $skippedSizes,
        public ?int $deepestBasisPoints,
        public array $otherOffers,
        public string $currency,
    ) {}
}
