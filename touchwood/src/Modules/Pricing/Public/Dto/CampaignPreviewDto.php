<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

/**
 * What a campaign's prices would do in a store (pricing.md §9.6 #8): **totals over everything it
 * reaches** - the products, the sizes it prices and those it skips, the deepest reduction (none when it
 * prices nothing) - **its products 20 at a time** by name in the page's language, each with every size
 * (`next` the cursor of the page after), and the other offers on those sizes in its dates. Writes nothing.
 */
final readonly class CampaignPreviewDto
{
    /**
     * @param  list<CampaignPreviewProductDto>  $products  this page's
     * @param  list<CampaignOfferDto>  $otherOffers
     */
    public function __construct(
        public array $products,
        public ?string $next,
        public int $reachedProducts,
        public int $reachedSizes,
        public int $skippedSizes,
        public ?int $deepestBasisPoints,
        public array $otherOffers,
    ) {}
}
