<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The account was anonymized and can never sign in again (spec §1.10). Sales, Feedback, B2B, Loyalty and Promotions keep their own "Deleted customer" copies.
 *
 * Ids only, after commit.
 */
final readonly class CustomerAnonymized implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
