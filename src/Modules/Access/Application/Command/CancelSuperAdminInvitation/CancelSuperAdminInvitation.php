<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelSuperAdminInvitation;

/**
 * Only from the server's console: a Super Admin who has not accepted is cancelled and freed
 * (amendment 30).
 */
final readonly class CancelSuperAdminInvitation
{
    public function __construct(
        public string $email,
    ) {}
}
