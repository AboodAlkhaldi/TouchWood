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
use Modules\Pricing\Presentation\Http\Resource\ShopWholesaleSizeData;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/**
 * **The wholesale prices on a product's page** (catalog.md amendment 35; the shop's screens' B8),
 * `pricing.wholesale`: for a company or a guest — an individual buys retail only (amendment 14(e)) —
 * each of the page's sizes that sells wholesale (as Catalog read them, in the product's order) and is
 * priced: what one piece costs now on a wholesale line below any band, and its bands — from each band's
 * start, what a piece costs there now (the band, or a sale or discount below it). Without VAT. Catalog's
 * own size price is retail only, so a size sold wholesale only is priced here alone. One read of the
 * sizes' candidates, none when no size sells wholesale; the store from Platform's cache.
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
        $money = static fn (?Money $unit): ?MoneyData => $unit === null || $unit->currencyCode !== $store->currencyCode ? null : MoneyData::of($unit, $store, $visit->locale);
        $sizes = [];

        foreach ($visit->wholesaleSizes as $variantId) {
            $of = $candidates[$variantId] ?? [];
            $price = $money(PriceResolution::resolve($of, SaleMode::Wholesale, 1, $now)?->unit);

            if ($price === null) {
                continue;
            }

            $starts = [];

            foreach ($of as $candidate) {
                if ($candidate->kind === PriceKind::Quantity) {
                    $starts[] = $candidate->fromQuantity;
                }
            }

            sort($starts);
            $bands = [];

            foreach (array_unique($starts) as $start) {
                $unit = $money(PriceResolution::resolve($of, SaleMode::Wholesale, $start, $now)?->unit);

                if ($unit !== null) {
                    $bands[] = new ShopBandData($start, $unit);
                }
            }

            $sizes[$variantId] = new ShopWholesaleSizeData($price, $bands);
        }

        return $sizes === [] ? null : (new ShopWholesaleData($sizes))->toArray();
    }
}
