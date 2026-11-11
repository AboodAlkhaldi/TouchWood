<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a campaign run's prices must be when they are saved (pricing.md §9.6 #1, #4), read under the
 * store's lock:
 * - every product, size, category and brand it names there, and every product it leaves out - a target
 *   named but not there is refused, never dropped in silence;
 * - **no deeper than the store's ceiling** (content.md Q2 (a)): a percentage above it, or a fixed price
 *   more than it below a size's retail price, refused whole, naming the products and the sizes - but in a
 *   wired store a fixed price skips a size it takes past the ceiling instead (§9.6 #5), as a fixed-amount
 *   discount does. A size with no price is skipped, never refused: the preview lists it.
 */
final readonly class CampaignCheck
{
    public function __construct(
        private CatalogApi $catalog,
        private CeilingCheck $ceiling,
    ) {}

    /**
     * @throws InvalidPricingInput|OverCeiling
     */
    public function check(StoreId $store, Campaign $campaign): void
    {
        $products = $campaign->targetIds(CampaignTargetKind::Product);
        $sizes = $campaign->targetIds(CampaignTargetKind::Size);
        $categories = $campaign->targetIds(CampaignTargetKind::Category);
        $brands = $campaign->targetIds(CampaignTargetKind::Brand);

        // A read a kind, each only when the run names one.
        if (($products !== [] && count($this->catalog->products($products)) !== count($products))
            || ($sizes !== [] && count($this->catalog->variants($sizes)) !== count($sizes))
            || ($categories !== [] && count($this->catalog->categoriesWithPaths($categories)) !== count($categories))
            || ($brands !== [] && count($this->catalog->brands($brands)) !== count($brands))) {
            throw new InvalidPricingInput('target');
        }

        if ($campaign->leftOut !== [] && count($this->catalog->products($campaign->leftOut)) !== count($campaign->leftOut)) {
            throw new InvalidPricingInput('left_out');
        }

        $ceiling = $this->ceiling->ceiling($store);
        $over = $this->ceiling->campaignsOverOf($store, $campaign, $ceiling);

        if ($over !== null) {
            throw new OverCeiling($ceiling, [], $over['sizes'], $this->ceiling->namesFor([], [], [$campaign->runId => $over]));
        }
    }
}
