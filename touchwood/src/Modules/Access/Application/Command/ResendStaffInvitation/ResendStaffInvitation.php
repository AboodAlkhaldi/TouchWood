<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResendStaffInvitation;

/**
 * A new invitation link for someone who has not accepted yet; the old link stops working
 * (spec §4.3).
 */
final readonly class ResendStaffInvitation
{
    public function __construct(
        public string $staffId,
    ) {}
}
