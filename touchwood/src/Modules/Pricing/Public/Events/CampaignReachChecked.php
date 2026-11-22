<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A wired store's sizes had their prices written again, and these are the campaign runs each still skips
 * past the ceiling (pricing.md §9.6 #5; sync.md §9.5 amendment 2 (b)): Sync closes a "campaign skipped"
 * entry the moment no run skips its size any more - the price lowered, the ceiling raised, the run
 * changed, ended or removed. As `DiscountReachChecked`: sent only for a wired store whose sizes a run
 * reaches, or as a run is saved, changed, ended or removed, after the change commits; ids only.
 */
final readonly class CampaignReachChecked implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $variantIds  the sizes written again
     * @param  list<array{runId: string, variantId: string}>  $skipped  of them, those a run still skips
     */
    public function __construct(
        public string $eventId,
        public string $storeId,
        public array $variantIds,
        public array $skipped,
        public DateTimeImmutable $occurredAt,
    ) {}
}
