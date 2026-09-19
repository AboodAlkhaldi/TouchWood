<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Dto\StaffNotificationPreferenceDto;

/**
 * What other modules may ask Access (spec §2.1). The customer and address reads arrive with
 * customer accounts (steps 4 and 5).
 */
interface AccessApi
{
    public function staff(string $staffId): ?StaffDto;

    /**
     * For Ops, when it sends staff notifications: every topic, with its email and panel toggles.
     *
     * @return list<StaffNotificationPreferenceDto>
     */
    public function staffNotificationPreferences(string $staffId): array;
}
