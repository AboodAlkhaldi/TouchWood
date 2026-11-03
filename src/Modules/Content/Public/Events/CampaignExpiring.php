<?php

declare(strict_types=1);

namespace Modules\Content\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A campaign is about to end (handoff §13.2; ops.md §1.6 S8). `endsAt` is the moment it ends. Ops alerts the
 * store's staff on the Campaign expiry topic, and reads the campaign's name through `ContentApi`.
 *
 * Declared ahead of Content's build, so Ops' side could be finished first: **nothing fires it yet, Content's
 * build will**, and its spec decides how long before the end. Ids only, through the outbox, after commit.
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
