<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query;

use Modules\Access\Public\Dto\StaffAlertRecipientDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;

/**
 * Who receives a staff alert (amendment 80; ops.md §1.4), in **one statement**: no read per person.
 *
 * Whether a permission is held is decided by the caller from the catalog, exactly as RoleAuthorizer
 * decides it, and handed in as plain rules; the statement only applies them to every account at once.
 */
interface StaffAlertRecipients
{
    /**
     * The **active** staff members who hold a **per-store** permission in the store, Super Admins always among them, each with
     * their switches for the topic.
     *
     * @param  bool  $byRole  a role may hold it: held by whoever's role holds it in the store
     * @param  bool  $adminOnly  only an admin role holds it (with $byRole)
     * @return list<StaffAlertRecipientDto> by id
     */
    public function recipients(string $storeId, StaffNotificationTopic $topic, string $permission, bool $byRole, bool $adminOnly): array;
}
