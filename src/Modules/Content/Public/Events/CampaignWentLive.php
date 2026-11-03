<?php

declare(strict_types=1);

namespace Modules\Content\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A campaign went live (handoff §13.2; ops.md §1.7, owner, round 3 #11). `noticeId` is the offer notice the
 * campaign carries, null for none: Ops starts it. Content never calls Ops.
 *
 * Declared ahead of Content's build, so Ops' side could be finished first: **nothing fires it yet, Content's
 * build will.** Ids only, through the outbox, after commit.
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
