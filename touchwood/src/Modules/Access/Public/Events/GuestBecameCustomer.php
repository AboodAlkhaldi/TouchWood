<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The visitor behind this guest id is now a customer (spec §1.7, §6): Sales moves their cart when
 * they registered, and merges it into the account's cart when they signed in. Ids only, after commit.
 */
final readonly class GuestBecameCustomer implements ShouldDispatchAfterCommit
{
    public const string REGISTERED = 'REGISTERED';

    public const string SIGNED_IN = 'SIGNED_IN';

    /**
     * @param  string  $how  self::REGISTERED or self::SIGNED_IN
     */
    public function __construct(
        public string $eventId,
        public string $guestId,
        public string $customerId,
        public string $how,
        public DateTimeImmutable $occurredAt,
    ) {}
}
