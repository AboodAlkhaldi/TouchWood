<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Dto\StaffNotificationPreferenceDto;

/**
 * What other modules may ask Access (spec §2.1). The address reads arrive with addresses (step 5).
 */
interface AccessApi
{
    public function customer(string $customerId): ?CustomerDto;

    /**
     * The person's part of handoff §7.4: active, email and phone verified, no deletion pending.
     * Sales adds the company's part for a company account, which B2B owns (spec §1.1).
     */
    public function customerMayOrder(string $customerId): bool;

    public function staff(string $staffId): ?StaffDto;

    /**
     * For Ops, when it sends staff notifications: every topic, with its email and panel toggles.
     *
     * @return list<StaffNotificationPreferenceDto>
     */
    public function staffNotificationPreferences(string $staffId): array;
}
