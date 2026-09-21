<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelStaffInvitation;

/**
 * The link stops working; the person stays invited, and a new link can be resent later
 * (amendment 29). Cancelling the account itself is CancelStaffAccount.
 */
final readonly class CancelStaffInvitation
{
    public function __construct(
        public string $staffId,
    ) {}
}
