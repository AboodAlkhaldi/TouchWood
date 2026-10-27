<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Storefront;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Presentation\Http\Resource\ShopBandData;
use Modules\Pricing\Presentation\Http\Resource\ShopWholesaleData;
use Modules\Pricing\Public\Enums\PriceKind;

/**
 * **The wholesale bands on a product's page** (catalog.md amendment 35; the shop's screens' B8),
 * `pricing.wholesale`: for a company or a guest — an individual buys retail only (amendment 14(e)) —
 * each of the page's sizes that sells wholesale (as Catalog read them, in the product's order), with the
 * bands it has: from each band's start, what one piece costs now on a wholesale line (the band, or a
 * sale or discount below it), without VAT. A size with no band is left out; none at all draws nothing.
 * One read of the sizes' candidates, none when no size sells wholesale; the store from Platform's cache.
 */
final readonly class WholesaleBandsSection implements ShopProductSection
{
    public function __construct(
        private PriceCandidates $candidates,
        private PlatformApi $platform,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function read(ShopProductVisitDto $visit): ?array
    {
        if ($visit->viewer === ShopperKind::Individual || $visit->wholesaleSizes === []) {
            return null;
        }

        $candidates = $this->candidates->forVariants($visit->store, $visit->wholesaleSizes);
        $store = $candidates === [] ? null : $this->platform->store($visit->store);

        if ($store === null) {
            return null;
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $bands = [];

        foreach ($visit->wholesaleSizes as $variantId) {
            $of = $candidates[$variantId] ?? [];
            $starts = [];

            foreach ($of as $candidate) {
                if ($candidate->kind === PriceKind::Quantity) {
                    $starts[] = $candidate->fromQuantity;
                }
            }

            sort($starts);

            foreach (array_unique($starts) as $start) {
                $unit = PriceResolution::resolve($of, SaleMode::Wholesale, $start, $now)?->unit;

                if ($unit !== null && $unit->currencyCode === $store->currencyCode) {
                    $bands[$variantId][] = new ShopBandData($start, MoneyData::of($unit, $store, $visit->locale));
                }
            }
        }

        return $bands === [] ? null : (new ShopWholesaleData($bands))->toArray();
    }
}
