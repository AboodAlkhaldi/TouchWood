<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ListCompanies;

/**
 * One row of the staff company list.
 */
final readonly class CompanySummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $homeStoreId,
        /** When the application waiting for a decision was sent; null when none waits. */
        public ?string $waitingSince,
        /** The waiting application's company type was deactivated since it was sent (§1.3). */
        public bool $typeDeactivatedSinceSent,
        public ?string $statusChangedAt,
    ) {}
}
