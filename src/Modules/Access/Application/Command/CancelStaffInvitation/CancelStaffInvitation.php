<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelStaffInvitation;

/**
 * The invitation stops working and the account is disabled (spec §4.3); enabling it later sends a
 * new invitation.
 */
final readonly class CancelStaffInvitation
{
    public function __construct(
        public string $staffId,
    ) {}
}
