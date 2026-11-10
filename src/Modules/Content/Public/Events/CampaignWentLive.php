<?php

declare(strict_types=1);

namespace Modules\Content\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A campaign run went live (handoff §13.2; ops.md §1.7, owner, round 3 #11; content.md §1.7 #9). `campaignId` is the
 * **run's** id; `noticeId` the offer notice the run carries, null for none: Ops starts it. Content never calls Ops.
 * Once a run, fired by `AdvanceCampaignsJob`'s conditional update. Ids only, through the outbox, after commit.
 */
final readonly class CampaignWentLive implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $campaignId,
        public string $storeId,
        public ?string $noticeId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
