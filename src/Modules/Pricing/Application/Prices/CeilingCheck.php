<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The store's discount ceiling against its sales (pricing.md §1.12; owner, 2026-10-11/13): which sales
 * running or scheduled would go deeper than a ceiling - at today's retail prices, or at retail prices a
 * change is about to set.
 */
final readonly class CeilingCheck
{
    public function __construct(
        private PlatformApi $platform,
        private Sales $sales,
        private RetailPrices $retailPrices,
    ) {}

    public function ceiling(StoreId $store): int
    {
        return (int) $this->platform->setting(PricingSettings::MAX_DISCOUNT_PERCENT, $store)->value;
    }

    /**
     * @param  array<string, Money>  $retail  retail prices about to be set, by size, in place of today's
     * @return list<string> the sales that would go deeper than the ceiling
     */
    public function salesOver(StoreId $store, int $ceilingPercent, array $retail = []): array
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $sales = $retail === []
            ? $this->sales->notEnded($store, $now)
            : $this->sales->notEndedOn($store, array_map('strval', array_keys($retail)), $now);
        $sizes = [];

        foreach ($sales as $sale) {
            array_push($sizes, ...$sale->variantIds);
        }

        $sizes = array_values(array_unique($sizes));
        $prices = [...$this->retailPrices->forVariants($store, $sizes), ...$retail];
        $over = [];

        foreach ($sales as $sale) {
            foreach ($sale->variantIds as $variantId) {
                if (isset($prices[$variantId]) && $sale->depthOn($prices[$variantId]) > $ceilingPercent * 100) {
                    $over[] = $sale->id;

                    break;
                }
            }
        }

        return $over;
    }
}
