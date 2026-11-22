<?php

declare(strict_types=1);

namespace Modules\Content\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A campaign run is about to end (handoff §13.2; ops.md §1.6 S8; content.md §1.7 #11). `campaignId` is the **run's**
 * id, `endsAt` the moment it ends. Ops alerts the store's staff on the Campaign expiry topic, and reads the campaign's
 * name through `ContentApi`. Up to two a run - 72 and 24 hours before its end, by its length - each with its own
 * `eventId`, fired by `AdvanceCampaignsJob`. Ids only, through the outbox, after commit.
 */
final readonly class CampaignExpiring implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $campaignId,
        public string $storeId,
        public DateTimeImmutable $endsAt,
        public DateTimeImmutable $occurredAt,
    ) {}
}
