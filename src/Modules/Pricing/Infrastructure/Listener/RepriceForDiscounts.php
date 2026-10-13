<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Listener;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Events\CategoryMoved;
use Modules\Catalog\Public\Events\ProductChanged;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
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
    ) {}

    public function productChanged(ProductChanged $event): void
    {
        $this->reprice([$event->productId => true]);
    }

    public function productMadeReady(ProductMadeReady $event): void
    {
        $this->reprice([$event->productId => true]);
    }

    public function categoryMoved(CategoryMoved $event): void
    {
        $this->reprice(array_fill_keys($this->catalog->productIdsInCategory($event->categoryId), true));
    }

    /**
     * @param  array<string, true>  $products
     */
    private function reprice(array $products): void
    {
        if ($products === []) {
            return;
        }

        foreach ($this->discounts->storesWithDiscounts(CarbonImmutable::now()->toDateTimeImmutable()) as $storeId) {
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
