<?php

declare(strict_types=1);

namespace Modules\B2B\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An application left `DRAFT` (b2b.md §6): staff have one to review. Staff notifications listen,
 * once Ops exists.
 *
 * Ids only, after commit.
 */
final readonly class CompanyApplicationSubmitted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $companyId,
        public string $applicationId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
