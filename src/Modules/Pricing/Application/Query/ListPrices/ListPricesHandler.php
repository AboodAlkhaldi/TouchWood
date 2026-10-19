<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListPrices;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Shared\Application\Unauthorized;

/**
 * **The Prices screen** (`ListPrices`, pricing.md §4.3.3 P1), for whoever holds any of Pricing's three jobs
 * in the store. Pricing works out the sizes its filter keeps in its own reads, and Catalog pages them by
 * its order - product name in the page's language, then the size's place (Q16) - through `searchVariants`
 * (`only` / `except`; catalog.md amendment 23(a)):
 *
 * - **All**: the sizes switched on, and those priced here though switched off;
 * - **No Price**: the sizes switched on, less those priced;
 * - **On Sale Now**: the sizes whose one piece costs less than its retail price now - a sale or a discount
 *   winning (§1.6);
 * - **Has Wholesale Prices**: the sizes with bands, of those the list holds.
 *
 * Then each row's facts, read for the page's sizes at once. A fixed few queries however many rows - at
 * most nine, never one a row. A read: no lock, nothing written.
 */
final readonly class ListPricesHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private PriceCandidates $candidates,
        private WholesaleBands $bands,
        private PriceViews $views,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(ListPrices $query): PriceList
    {
        $store = $this->access->authorize($query->storeId);
        $storeId = $store->storeId();

        if (! in_array($query->filter, ListPrices::FILTERS, true)) {
            throw new InvalidPricingInput('filter');
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $priced = $this->retailPrices->pricedVariants($storeId);
        [$only, $except] = match ($query->filter) {
            ListPrices::NO_PRICE => [null, $priced],
            ListPrices::ON_SALE_NOW => [$this->onSale($this->candidates->reducedAt($storeId, $now), $now), null],
            ListPrices::HAS_WHOLESALE => [array_values(array_intersect(
                $this->bands->variantsWithBands($storeId),
                [...$this->catalog->switchedOnVariantIds($storeId), ...$priced],
            )), null],
            default => [array_values(array_unique([...$this->catalog->switchedOnVariantIds($storeId), ...$priced])), null],
        };

        $found = $this->catalog->searchVariants($storeId, $query->search, $only, $except, after: $query->after, limit: ListPrices::PER_PAGE);
        $ids = array_map(static fn (FoundVariantDto $row): string => $row->variantId, $found->rows);
        $retail = $this->retailPrices->records($storeId, $ids);
        $candidates = $this->candidates->forVariants($storeId, $ids);
        $bands = $this->bands->forVariants($storeId, $ids);
        $terms = $this->catalog->wholesaleOf($storeId, $ids);
        $rows = [];

        foreach ($found->rows as $row) {
            $id = $row->variantId;
            $term = $terms[$id] ?? null;

            $rows[] = new PriceRow(
                $this->views->size($row),
                $this->views->retail($retail[$id] ?? null, $store),
                $this->views->today($candidates[$id] ?? [], SaleMode::Retail, 1, $now, $store),
                $term !== null && $term->soldWholesale && ! $term->soldRetail,
                count($bands[$id] ?? []),
                ($bands[$id] ?? []) !== [] && ! ($term->soldWholesale ?? false),
            );
        }

        return new PriceList($rows, $found->next, $found->total);
    }

    /**
     * The sizes whose one piece costs less than the retail price now (§1.6): of those a sale or a
     * discount reaches, the ones it wins - not one an "always wins" at the retail price holds there.
     *
     * @param  array<string, list<Candidate>>  $reached
     * @return list<string>
     */
    private function onSale(array $reached, DateTimeImmutable $now): array
    {
        $sizes = [];

        foreach ($reached as $variantId => $candidates) {
            $price = PriceResolution::resolve($candidates, SaleMode::Retail, 1, $now);

            if ($price !== null && $price->unit->compareTo($price->listUnit) < 0) {
                $sizes[] = (string) $variantId;
            }
        }

        return $sizes;
    }
}
