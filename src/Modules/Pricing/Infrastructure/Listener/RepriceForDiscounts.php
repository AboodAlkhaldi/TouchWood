<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Listener;

use Carbon\CarbonImmutable;
use Closure;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Events\CategoryMoved;
use Modules\Catalog\Public\Events\ProductChanged;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Platform\Public\Events\SettingChanged;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\CeilingCheck;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * **A product moving into or out of a category follows at once** (pricing.md §1.5, §6.2): a product
 * changed (it may have moved), a draft made ready (its category change sent nothing before), or a
 * whole category moved under another parent - their priced sizes have their candidates written again
 * in every store with a discount not ended, so a discount covers what is in its category now. Each
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

        $fixed = array_values(array_filter(
            $this->discounts->notEnded($store, CarbonImmutable::now()->toDateTimeImmutable()),
            static fn (CategoryDiscount $discount): bool => $discount->amount !== null,
        ));

        if ($fixed === []) {
            return;
        }

        $this->change->run($store, function () use ($store, $fixed): array {
            $this->writer->refresh($store, array_keys($this->coverage->pricedSizes($store, $fixed)));

            return [null, []];
        });
    }

    /**
     * @param  Closure(): array<string, true>  $products  read only when a store has a discount: with none,
     *                                                    Catalog's change costs it nothing
     */
    private function reprice(Closure $products): void
    {
        $stores = $this->discounts->storesWithDiscounts(CarbonImmutable::now()->toDateTimeImmutable());
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
