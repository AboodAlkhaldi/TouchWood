<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetStaffAuthenticator;

/**
 * A Super Admin resets someone's authenticator app from the staff page, when its owner lost both the app
 * and the recovery codes (amendment 91).
 */
final readonly class ResetStaffAuthenticator
{
    public function __construct(
        public string $staffId,
    ) {}
}
