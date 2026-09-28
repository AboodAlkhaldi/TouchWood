<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * The company as it stands (b2b.md §1.1): the latest values sent, its status and what it was told.
 */
final readonly class CompanyView
{
    public function __construct(
        public string $id,
        public ApplicationValues $details,
        /** PENDING, APPROVED, REJECTED or SUSPENDED. */
        public string $status,
        /** Why it was rejected, suspended or reinstated. */
        public ?string $statusReason,
        public ?string $statusChangedAt,
        /** Approved, and nothing else (handoff §7.4). */
        public bool $mayOrder,
    ) {}
}
