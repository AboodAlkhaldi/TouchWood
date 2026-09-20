<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResendSuperAdminInvitation;

/**
 * Only from the server's console: a new 24-hour link for a Super Admin who has not accepted
 * (amendment 30).
 */
final readonly class ResendSuperAdminInvitation
{
    public function __construct(
        public string $email,
    ) {}
}
