<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffEmail;

/**
 * A new email for a staff member (amendment 17): an admin who manages them, or a Super Admin for
 * their own account. It takes effect when the link sent to the new address is used.
 */
final readonly class ChangeStaffEmail
{
    public function __construct(
        public string $staffId,
        public string $newEmail,
    ) {}
}
