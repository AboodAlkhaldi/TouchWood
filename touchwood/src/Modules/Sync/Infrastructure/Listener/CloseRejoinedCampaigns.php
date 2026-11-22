<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Listener;

use Modules\Pricing\Public\Events\CampaignReachChecked;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * A "campaign skipped" entry closes by itself the moment no campaign run skips its size any more (sync.md
 * §9.5 amendment 2 (b); pricing.md §9.6 #5) - the price lowered, the ceiling raised, the run changed,
 * ended or removed: Pricing wrote the size's prices again and names no run skipping it now. Closed by
 * `PRICING`, after Pricing's change commits, as a discount's entry (`CloseReachedDiscounts`); an error is
 * reported, never shown to staff as their change failing.
 */
final readonly class CloseRejoinedCampaigns
{
    public function __construct(
        private ReportEntries $entries,
    ) {}

    public function handle(CampaignReachChecked $event): void
    {
        try {
            $store = StoreId::fromString($event->storeId);

            // Most changes find nothing skipped: one look, and nothing more.
            if (! $this->entries->hasOpen($store, ReportKind::CampaignSkipped)) {
                return;
            }

            $runs = [];

            foreach ($event->skipped as $skipped) {
                $runs[$skipped['variantId']][] = $skipped['runId'];
            }

            $rejoined = array_values(array_diff($event->variantIds, array_map('strval', array_keys($runs))));
            $this->entries->close($store, ReportKind::CampaignSkipped, [], $rejoined, 'PRICING');
            // A size some run still skips keeps its entry, naming those runs alone - closed if none it named is left.
            $this->entries->keepCampaignRuns($store, $runs, 'PRICING');
        } catch (Throwable $error) {
            report($error);
        }
    }
}
