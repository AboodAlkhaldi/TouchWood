<?php

declare(strict_types=1);

namespace Modules\B2B\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * A company's status changed (b2b.md §6): sent, approved, rejected, suspended or reinstated. Ops
 * listens, later, to write to the customer; Pricing does not — prices follow the account type.
 *
 * Ids only, after commit.
 */
final readonly class CompanyStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public string $companyId,
        /** Null when the first application created the company, `PENDING`. */
        public ?CompanyStatus $from,
        public CompanyStatus $to,
        /** Why it was rejected, suspended or reinstated, in staff's words; null otherwise. */
        public ?string $reason,
        public DateTimeImmutable $occurredAt,
    ) {}
}
