<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Modules\Access\Public\Enums\AccountType;

/**
 * A customer account was created (spec §6). B2B starts a company's application from it; Ops may
 * welcome them. Ids only, dispatched after commit.
 */
final readonly class CustomerRegistered implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public AccountType $accountType,
        public string $storeId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
