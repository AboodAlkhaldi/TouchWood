<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store's current key is that many days - 30, 7 or 1 - from the expiry date typed when it was saved
 * (payments.md §1.2, §2.2; §9.7 #2): once per key and threshold, and only the nearest one when a key was
 * saved with less time left (gap 8.7). `mode` is `TEST` or `LIVE`; `expiresOn` is the date, `Y-m-d`. Ops
 * tells the Super Admins on the Payment problems topic.
 *
 * Ids only, through the outbox.
 */
final readonly class GatewayKeyExpiring implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $storeId,
        public string $mode,
        public int $daysLeft,
        public string $expiresOn,
        public DateTimeImmutable $occurredAt,
    ) {}
}
