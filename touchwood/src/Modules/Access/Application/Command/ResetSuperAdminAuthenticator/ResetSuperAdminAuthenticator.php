<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetSuperAdminAuthenticator;

/**
 * `access:super-admin:reset-authenticator {email}`: a lone Super Admin's app, reset on the server
 * (amendment 91).
 */
final readonly class ResetSuperAdminAuthenticator
{
    public function __construct(
        public string $email,
    ) {}
}
