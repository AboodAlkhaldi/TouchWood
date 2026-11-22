<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use DateTimeImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Domain\ValueObject\CampaignTarget;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Domain\ValueObject\DiscountLines;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a campaign run reaches (pricing.md §9.6 #1, #6): its products picked by name, the products of its
 * categories (and every one below them) and of its brands **now** - less those left out of them - and
 * its sizes picked by name; of their sizes, those with a retail price in the store - the only sizes it
 * can price. And, size by size, the price it gives: never an archived size, or one of an archived product.
 *
 * Reads for any number of runs: a category's products and a brand's in one read of Catalog each, their
 * sizes in one more.
 */
final readonly class CampaignCoverage
{
    /** Sizes a read. */
    private const int CHUNK = 1000;

    public function __construct(
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
    ) {}

    /**
     * @param  list<Campaign>  $campaigns
     * @return array<string, array<string, true>> run id => the products it reaches now (picked by name, in its categories and brands, and its sizes' products are not counted here)
     */
    public function products(array $campaigns): array
    {
        if ($campaigns === []) {
            return [];
        }

        $categoryIds = $brandIds = [];

        foreach ($campaigns as $campaign) {
            array_push($categoryIds, ...$campaign->targetIds(CampaignTargetKind::Category));
            array_push($brandIds, ...$campaign->targetIds(CampaignTargetKind::Brand));
        }

        $inCategory = $categoryIds === [] ? [] : $this->catalog->productIdsInCategories(array_values(array_unique($categoryIds)));
        $ofBrand = $brandIds === [] ? [] : $this->catalog->productIdsOfBrands(array_values(array_unique($brandIds)));
        $each = [];

        foreach ($campaigns as $campaign) {
            $products = [];

            foreach ($campaign->targetIds(CampaignTargetKind::Category) as $categoryId) {
                $products += array_fill_keys($inCategory[$categoryId] ?? [], true);
            }

            foreach ($campaign->targetIds(CampaignTargetKind::Brand) as $brandId) {
                $products += array_fill_keys($ofBrand[$brandId] ?? [], true);
            }

            // Left out of its categories and brands; a product picked by name stays as picked.
            foreach ($campaign->leftOut as $productId) {
                unset($products[$productId]);
            }

            $each[$campaign->runId] = $products + array_fill_keys($campaign->targetIds(CampaignTargetKind::Product), true);
        }

        return $each;
    }

    /**
     * The sizes these runs may price in the store: their products' sizes not archived, and the sizes picked
     * by name, those with a retail price, in id order.
     *
     * @param  list<Campaign>  $campaigns
     * @return list<string>
     */
    public function pricedSizes(StoreId $store, array $campaigns): array
    {
        $products = [];
        $sizes = [];

        foreach ($this->products($campaigns) as $reached) {
            $products += $reached;
        }

        foreach ($campaigns as $campaign) {
            array_push($sizes, ...$campaign->targetIds(CampaignTargetKind::Size));
        }

        foreach ($products === [] ? [] : $this->catalog->variantIdsOfProducts(array_map('strval', array_keys($products))) as $ofProduct) {
            array_push($sizes, ...$ofProduct);
        }

        $sizes = array_values(array_unique($sizes));
        $priced = [];

        foreach (array_chunk($sizes, self::CHUNK) as $chunk) {
            array_push($priced, ...array_map('strval', array_keys($this->retailPrices->forVariants($store, $chunk))));
        }

        sort($priced);

        return $priced;
    }

    /**
     * What a run's price on a size is judged by, from Catalog, for a chunk of sizes in two reads: each
     * size's product, brand and categories (its own and every one above it), and whether it may be priced
     * at all - neither it nor its product archived.
     *
     * @param  list<string>  $variantIds
     * @return array<string, array{productId: string, brandId: string, categoryIds: list<string>, archived: bool}>
     */
    public function facts(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $briefs = $this->catalog->variantBriefs($variantIds);
        $facts = [];

        foreach ($this->catalog->lineFacts($variantIds) as $variantId => $line) {
            $brief = $briefs[$variantId] ?? null;
            $facts[(string) $variantId] = [
                'productId' => $line->productId,
                'brandId' => $line->brandId,
                'categoryIds' => $line->categoryIds,
                'archived' => $brief === null || $brief->isArchived || $brief->productStage === ProductStage::Archived,
            ];
        }

        return $facts;
    }

    /**
     * The runs pricing these sizes whose dates meet these, on a line these price too - for the notes and
     * previews naming other offers (§9.6 #7) - read from the sizes' candidates the caller has already, so
     * naming them costs no query: a run's candidates are exactly the sizes it prices (not skipped, priced,
     * never archived), with its dates and lines.
     *
     * @param  array<string, list<Candidate>>  $candidates  variant id => its candidates
     * @return list<array{runId: string, startsAt: DateTimeImmutable, endsAt: DateTimeImmutable|null, lines: string, variantIds: list<string>}>
     */
    public static function runsIn(array $candidates, DateTimeImmutable $start, ?DateTimeImmutable $end, ?DiscountLines $lines = null): array
    {
        $runs = [];

        foreach ($candidates as $variantId => $ofSize) {
            foreach ($ofSize as $candidate) {
                if ($candidate->kind !== PriceKind::Campaign || $candidate->sourceId === null || $candidate->startsAt === null
                    || ($lines !== null && ! $lines->covers($candidate->mode))
                    || ($end !== null && $candidate->startsAt >= $end) || ($candidate->endsAt !== null && $start >= $candidate->endsAt)) {
                    continue;
                }

                $runs[$candidate->sourceId] ??= ['runId' => $candidate->sourceId, 'startsAt' => $candidate->startsAt, 'endsAt' => $candidate->endsAt, 'modes' => [], 'variantIds' => []];
                $runs[$candidate->sourceId]['modes'][$candidate->mode->value] = true;
                $runs[$candidate->sourceId]['variantIds'][(string) $variantId] = true;
            }
        }

        return array_values(array_map(static fn (array $run): array => [
            'runId' => $run['runId'],
            'startsAt' => $run['startsAt'],
            'endsAt' => $run['endsAt'],
            'lines' => count($run['modes']) === 2 ? DiscountLines::Both->value : (string) array_key_first($run['modes']),
            'variantIds' => array_map('strval', array_keys($run['variantIds'])),
        ], $runs));
    }

    /**
     * A run's price on a size, the target giving it and how deep it goes: null when it does not reach the
     * size, the size or its product is archived, or a percentage would leave 0.
     *
     * @param  array{productId: string, brandId: string, categoryIds: list<string>, archived: bool}|null  $facts
     * @return array{target: CampaignTarget, price: Money, depth: int}|null
     */
    public static function priceOn(Campaign $campaign, string $variantId, ?array $facts, Money $retail): ?array
    {
        if ($facts === null || $facts['archived']) {
            return null;
        }

        $target = $campaign->targetFor($variantId, $facts['productId'], $facts['brandId'], $facts['categoryIds']);
        $price = $target?->priceOn($retail);

        return $target === null || $price === null ? null : ['target' => $target, 'price' => $price, 'depth' => Campaign::depthOn($target, $retail)];
    }
}
