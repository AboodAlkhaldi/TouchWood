<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\StartAuthenticatorSetup;

/**
 * Setting up an authenticator app, or changing it (amendment 91): the current password first, then a
 * new secret waits for its first code.
 */
final readonly class StartAuthenticatorSetup
{
    public function __construct(
        #[\SensitiveParameter]
        public string $currentPassword,
        public string $ip,
    ) {}
}
