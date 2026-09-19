<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\AcceptStaffInvitation;

/**
 * Accepting, first half (spec §1.4, amendment 15): the invitee chooses a password and confirms the
 * phone the admin entered — or corrects it — and an SMS code is sent to it. Sending it again asks
 * for a new code.
 */
final readonly class AcceptStaffInvitation
{
    /**
     * @param  string  $token  from the invitation link
     */
    public function __construct(
        public string $token,
        public string $password,
        public string $phone,
    ) {}
}
