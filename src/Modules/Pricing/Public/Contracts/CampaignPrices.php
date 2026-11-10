<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Contracts;

use DateTimeImmutable;
use Modules\Pricing\Public\Dto\CampaignPreviewDto;
use Modules\Pricing\Public\Dto\CampaignPriceSetDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Campaign prices** (pricing.md §9.6; content.md §2.3 P1): Content keeps the campaign, its stores and a
 * draft's choices; Pricing holds and applies a run's prices from the moment it is scheduled. Module to
 * module, so no permission is checked here - Content checks `content.campaign.manage` and
 * `pricing.price.edit` itself. A run is one store's part of a campaign, named by Content's run id.
 *
 * A run's price on a size (the build's picks, stated in the PR): the size's own target if it has one,
 * else its product's, else the lowest of its categories' and brand's - those two only for a product not
 * left out; an archived size or product never; a percentage rounded half up to the coin.
 */
interface CampaignPrices
{
    /**
     * What these prices would do in the store in those dates: each size's new price, the sizes skipped and
     * why, the other offers on them then, the deepest reduction; 20 products a page (`$after` the cursor),
     * totals for all. Writes nothing. A run being changed is named (`$runId`) so it is not its own other
     * offer.
     */
    public function preview(StoreId $store, CampaignPriceSetDto $prices, DateTimeImmutable $start, DateTimeImmutable $end, ?string $after = null, ?string $runId = null): CampaignPreviewDto;

    /**
     * Writes or replaces the run's prices and their candidates of kind `CAMPAIGN`, under the store's pricing
     * lock and inside the caller's transaction - called only when the run is scheduled (content.md §1.7
     * #14) - with the timed tasks at its start and end. Refused: an end not after the start, a percentage
     * not from 0.01 to 99.99, a fixed price on anything but a size or not in the store's currency, a target
     * named twice or not there, a run of another store (`pricing.invalid_pricing_input`,
     * `pricing.window_invalid`, `pricing.percent_out_of_range`); deeper than the store's ceiling on any size
     * it prices, naming the products (`pricing.over_ceiling`) - but in a wired store a fixed price the
     * provider's price takes past it skips that size instead (§9.6 #5). A run already ended is not changed
     * (`pricing.not_changeable`).
     */
    public function save(StoreId $store, string $runId, CampaignPriceSetDto $prices, DateTimeImmutable $start, DateTimeImmutable $end): void;

    /**
     * A scheduled or live run's new end, after its start and never before now (`pricing.window_invalid`);
     * an ended run is never revived (`pricing.not_changeable`); a run Pricing does not hold changes nothing.
     * Every moment is kept to the whole second, taken down.
     */
    public function changeEnd(StoreId $store, string $runId, DateTimeImmutable $end): void;

    /**
     * A started run ends now (at the whole second); one not started - or started this very second - is
     * removed instead; an ended one, or one Pricing does not hold, is left as it is. Content's Cancel calls
     * this too: Pricing judges "started" by the run's own dates, which Content's state can lag behind.
     */
    public function endNow(StoreId $store, string $runId): void;

    /** The run's prices go, their candidates with them; a run Pricing does not hold changes nothing. */
    public function remove(StoreId $store, string $runId): void;

    /** The run's prices as saved, for the campaign's form; null for a run Pricing does not hold there. */
    public function read(StoreId $store, string $runId): ?CampaignPriceSetDto;

    /**
     * The products the run reaches now, in id order after `$after`, at most `$limit` (1 to 100) - for the
     * shop's campaign page, which keeps only those it may show.
     *
     * @return list<string>
     */
    public function productIds(StoreId $store, string $runId, ?string $after, int $limit): array;

    /**
     * The sizes the run skips now because a provider's price would take it past the store's ceiling (§9.6
     * #5), in id order, for the campaign's page; one query.
     *
     * @return list<string>
     */
    public function skippedSizes(StoreId $store, string $runId): array;
}
