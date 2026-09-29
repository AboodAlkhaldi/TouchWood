<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewCompany;

use Modules\B2B\Application\Query\ViewMyCompany\ApplicationValues;

/**
 * A company as staff review it (b2b.md §3.2): its details, status and reason, who changed the status
 * last, the account holder, and the applications it sent, newest first. Never a draft.
 */
final readonly class StaffCompanyView
{
    /**
     * @param  list<StaffApplicationView>  $applications  newest first
     */
    public function __construct(
        public string $id,
        public ApplicationValues $details,
        public string $status,
        public ?string $statusBeforeSuspension,
        public ?string $statusReason,
        public ?string $statusChangedAt,
        public ?string $statusChangedBy,
        public string $homeStoreId,
        public bool $mayOrder,
        public ?HolderView $holder,
        public array $applications,
    ) {}
}
