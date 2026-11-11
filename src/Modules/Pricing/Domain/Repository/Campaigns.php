<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use DateTimeImmutable;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Shared\Domain\ValueObject\StoreId;

/**
 * Campaign runs' prices (pricing.md §9.6; `pricing.campaign_sets`, `campaign_targets`,
 * `campaign_left_out`) and the sizes each skips now in a wired store (`campaign_skips`). Every query names
 * its store, but `find`, which finds the run's store with it, and the daily check's.
 */
interface Campaigns
{
    public function find(string $runId): ?Campaign;

    /** A new run's prices or a changed run's, with its targets and the products it leaves out. */
    public function save(Campaign $campaign): void;

    public function remove(string $runId): void;

    /**
     * @return list<Campaign> every run in the store not ended by then
     */
    public function notEnded(StoreId $store, DateTimeImmutable $at): array;

    /**
     * The stores with a category discount and those with a campaign run not ended by then, in one read - what
     * a product changed in Catalog asks first, so it costs one query as it did before campaigns.
     *
     * @return array{discounts: list<string>, campaigns: list<string>}
     */
    public function storesWithOffers(DateTimeImmutable $at): array;

    /**
     * The daily safety check's read (§1.10): every run, in any store, whose start or end fell after
     * `$from` and by `$to`.
     *
     * @return list<Campaign>
     */
    public function windowsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * The skips of these sizes in the store become exactly these.
     *
     * @param  list<string>  $variantIds  the sizes written again
     * @param  list<array{runId: string, variantId: string}>  $skipped
     */
    public function replaceSkips(StoreId $store, array $variantIds, array $skipped): void;

    /**
     * @return list<string> the sizes the run skips now, in id order
     */
    public function skippedSizes(StoreId $store, string $runId): array;

    /**
     * How many sizes each run's `CAMPAIGN` candidates name, in one query; a run with none left out.
     *
     * @param  list<string>  $runIds
     * @return array<string, int>
     */
    public function reachedSizes(array $runIds): array;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, list<string>> size id => the runs skipping it now, in id order
     */
    public function skipsOf(StoreId $store, array $variantIds): array;
}
