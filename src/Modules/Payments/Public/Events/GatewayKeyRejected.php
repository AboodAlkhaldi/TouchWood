<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * MyFatoorah refused the store's **current** key, and online payment stopped by itself (payments.md §1.2,
 * §9.7 #3): once until a new key is set. A 401 on an earlier key sends nothing.
 *
 * Ids only, through the outbox.
 */
final readonly class GatewayKeyRejected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $storeId,
        public string $mode,
        public DateTimeImmutable $occurredAt,
    ) {}
}
