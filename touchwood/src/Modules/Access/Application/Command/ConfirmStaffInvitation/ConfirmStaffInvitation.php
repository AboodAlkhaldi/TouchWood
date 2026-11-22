<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ConfirmStaffInvitation;

/**
 * Accepting, second half: the SMS code. When it is right the account is active, with the password
 * the invitee chose and the phone they verified.
 */
final readonly class ConfirmStaffInvitation
{
    public function __construct(
        public string $token,
        public string $code,
    ) {}
}
