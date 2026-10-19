<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewProductPrices;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReachViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\ReaderStores;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Domain\ValueObject\WindowState;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A product's prices in every store the reader prices in** (`ViewProductPrices`, pricing.md §4.3.3, the
 * product page's Pricing section through Catalog's `ProductStoreSections`): of the page's stores, those
 * where the reader holds any of Pricing's three jobs - an off store only for whoever may switch stores -
 * each with its sizes' prices, the product's sales and the discounts reaching it.
 *
 * **One read for every card**: each table is read once for all the stores, so the tab costs a fixed few
 * queries however many stores and sizes (frontend.md §5), the wired stores among them too. A reader with
 * none of the jobs anywhere costs nothing more than the asking. A read: no lock, nothing written.
 */
final readonly class ViewProductPricesHandler
{
    public function __construct(
        private ReaderStores $stores,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private PriceCandidates $candidates,
        private WholesaleBands $bands,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private StoreWiring $wiring,
        private PriceViews $views,
        private ReachViews $reach,
    ) {}

    /**
     * @return list<ProductStorePrices> in the page's order of its stores; a store the reader may not see left out
     */
    public function handle(ViewProductPrices $query): array
    {
        $asked = array_map('strtolower', $query->storeIds);
        $mine = array_filter(
            $this->stores->authorize(ReadAccess::ANY_OF_THE_THREE),
            static fn (array $held, string $id): bool => in_array($id, $asked, true),
            ARRAY_FILTER_USE_BOTH,
        );
        $variantIds = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $query->variantIds)));

        if ($mine === []) {
            return [];
        }

        $productId = strtolower(trim($query->productId));
        $storeIds = array_map(static fn (array $held): StoreId => $held['store']->storeId(), array_values($mine));
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $retail = $this->retailPrices->recordsInStores($storeIds, $variantIds);
        $candidates = $this->candidates->inStores($storeIds, $variantIds);
        $bands = $this->bands->inStores($storeIds, $variantIds);
        $sales = $this->sales->notEndedOfProduct($storeIds, $productId, $now);
        $discounts = $this->discounts->notEndedInStores($storeIds, $now);
        // The product's category and every one above it, from any of its sizes (all share the product).
        $categoryIds = $discounts === [] || $variantIds === [] ? [] : ($this->catalog->lineFacts([$variantIds[0]])[$variantIds[0]]->categoryIds ?? []);
        $reaching = ReachViews::reaching($discounts, $productId, $categoryIds);
        $paths = $reaching === [] ? [] : $this->catalog->categoriesWithPaths(array_values(array_unique(array_map(static fn (CategoryDiscount $discount): string => $discount->categoryId, $reaching))));
        $wired = array_fill_keys(array_map('strtolower', $this->wiring->wiredStores()), true);
        $cards = [];

        foreach ($asked as $id) {
            if (! isset($mine[$id])) {
                continue;
            }

            ['store' => $store, 'jobs' => $jobs] = $mine[$id];
            $sizes = [];

            foreach ($variantIds as $variantId) {
                $sizes[] = new ProductSizePrices(
                    $variantId,
                    $this->views->retail($retail[$id][$variantId] ?? null, $store),
                    $this->views->today($candidates[$id][$variantId] ?? [], SaleMode::Retail, 1, $now, $store),
                    $this->views->bands($bands[$id][$variantId] ?? [], $store),
                );
            }

            $cards[] = new ProductStorePrices(
                $id,
                in_array(PricingPermissions::PRICE_EDIT, $jobs, true),
                in_array(PricingPermissions::WHOLESALE_EDIT, $jobs, true),
                in_array(PricingPermissions::CATEGORY_DISCOUNT_MANAGE, $jobs, true),
                isset($wired[$id]),
                $sizes,
                array_values(array_map(fn ($sale): ProductSale => new ProductSale(
                    $sale->id,
                    WindowState::of($sale->startsAt, $sale->endsAt, $now)->value,
                    $sale->percentBasisPoints,
                    $sale->price === null ? null : $this->views->money($sale->price, $store),
                    $sale->variantIds,
                    $sale->startsAt->format(DateTimeInterface::ATOM),
                    $sale->endsAt?->format(DateTimeInterface::ATOM),
                    $sale->alwaysWins,
                ), array_filter($sales, static fn ($sale): bool => $sale->storeId === $id))),
                array_values(array_map(fn (CategoryDiscount $discount): ProductDiscount => new ProductDiscount(
                    $discount->id,
                    $discount->categoryId,
                    $this->reach->path($paths[$discount->categoryId] ?? null),
                    WindowState::of($discount->startsAt, $discount->endsAt, $now)->value,
                    $discount->percentBasisPoints,
                    $discount->amount === null ? null : $this->views->money($discount->amount, $store),
                    $discount->lines->value,
                    $discount->startsAt->format(DateTimeInterface::ATOM),
                    $discount->endsAt?->format(DateTimeInterface::ATOM),
                    $discount->alwaysWins,
                ), array_filter($reaching, static fn (CategoryDiscount $discount): bool => $discount->storeId === $id))),
            );
        }

        return $cards;
    }
}
