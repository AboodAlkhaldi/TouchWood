<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Listener;

use Carbon\CarbonImmutable;
use Closure;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Events\CategoryMoved;
use Modules\Catalog\Public\Events\ProductArchived;
use Modules\Catalog\Public\Events\ProductChanged;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\ProductRestored;
use Modules\Catalog\Public\Events\VariantArchived;
use Modules\Catalog\Public\Events\VariantRestored;
use Modules\Platform\Public\Events\SettingChanged;
use Modules\Pricing\Application\Prices\CampaignCoverage;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\CeilingCheck;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Repository\Campaigns;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * **A product moving into or out of a category follows at once** (pricing.md §1.5, §6.2): a product
 * changed (it may have moved, or changed brand), a draft made ready (its category change sent nothing
 * before), or a whole category moved under another parent - their priced sizes have their candidates
 * written again in every store with a discount or a campaign run not ended, so a discount covers what
 * is in its category now, and a run what is in its categories and brands now (§9.6 #6; content.md Q18 (a)). Each
 * store in its own transaction under its pricing lock, after Catalog's change committed; a failure is
 * reported, never thrown back at Catalog - the repair (`pricing:candidates:rebuild`) writes them again.
 */
final readonly class RepriceForDiscounts
{
    public function __construct(
        private CategoryDiscounts $discounts,
        private DiscountCoverage $coverage,
        private CandidateWriter $writer,
        private PricingChange $change,
        private CatalogApi $catalog,
        private CeilingCheck $ceiling,
        private Campaigns $campaigns,
        private CampaignCoverage $campaignCoverage,
    ) {}

    public function productChanged(ProductChanged $event): void
    {
        $this->reprice(static fn (): array => [$event->productId => true]);
    }

    public function productMadeReady(ProductMadeReady $event): void
    {
        $this->reprice(static fn (): array => [$event->productId => true]);
    }

    public function categoryMoved(CategoryMoved $event): void
    {
        $this->reprice(fn (): array => array_fill_keys($this->catalog->productIdsInCategory($event->categoryId), true));
    }

    /**
     * The ceiling changed in a wired store: its fixed-amount discounts reach or skip sizes again
     * (sync.md §9.3 #17, #20). Elsewhere the ceiling never decides what a discount reaches.
     */
    public function ceilingChanged(SettingChanged $event): void
    {
        if ($event->key !== PricingSettings::MAX_DISCOUNT_PERCENT || $event->storeId === null) {
            return;
        }

        $store = StoreId::fromString($event->storeId);

        if (! $this->ceiling->skipsPastCeiling($store)) {
            return;
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $fixed = array_values(array_filter(
            $this->discounts->notEnded($store, $now),
            static fn (CategoryDiscount $discount): bool => $discount->amount !== null,
        ));
        // A campaign's fixed prices the same way (pricing.md §9.6 #5).
        $campaigns = array_values(array_filter($this->campaigns->notEnded($store, $now), static fn (Campaign $campaign): bool => $campaign->hasFixedPrices()));

        if ($fixed === [] && $campaigns === []) {
            return;
        }

        $this->change->run($store, function () use ($store, $fixed, $campaigns): array {
            $sizes = [...($fixed === [] ? [] : array_keys($this->coverage->pricedSizes($store, $fixed))), ...($campaigns === [] ? [] : $this->campaignCoverage->pricedSizes($store, $campaigns))];
            $sizes = array_values(array_unique(array_map('strval', $sizes)));
            sort($sizes);
            $this->writer->refresh($store, $sizes, campaignsChanged: $campaigns !== []);

            return [null, []];
        });
    }

    /**
     * A size or product archived or restored: a campaign never prices an archived one (pricing.md §9.6
     * #1), so its sizes are written again in every store with a run not ended. Sales and discounts keep
     * an archived size's candidates as before: it is not on sale anyway (§6.2).
     */
    public function archivedOrRestored(VariantArchived|VariantRestored|ProductArchived|ProductRestored $event): void
    {
        $this->reprice(static fn (): array => [$event->productId => true], campaignsOnly: true);
    }

    /**
     * @param  Closure(): array<string, true>  $products  read only when a store has a discount or a campaign
     *                                                    run: with none, Catalog's change costs it nothing
     */
    private function reprice(Closure $products, bool $campaignsOnly = false): void
    {
        // The stores with a discount and those with a run, in one read (as before campaigns, one query).
        $offers = $this->campaigns->storesWithOffers(CarbonImmutable::now()->toDateTimeImmutable());
        $stores = array_values(array_unique([...($campaignsOnly ? [] : $offers['discounts']), ...$offers['campaigns']]));
        sort($stores);
        $products = $stores === [] ? [] : $products();

        if ($products === []) {
            return;
        }

        foreach ($stores as $storeId) {
            $store = StoreId::fromString($storeId);

            try {
                $this->change->run($store, function () use ($store, $products): array {
                    $this->writer->refresh($store, array_keys($this->coverage->pricedSizesOf($store, $products)));

                    return [null, []];
                });
            } catch (Throwable $error) {
                report($error);
            }
        }
    }
}
