<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RemoveOwnAuthenticator;

/**
 * Removing one's own authenticator app and its recovery codes, after the current password (amendment 91).
 */
final readonly class RemoveOwnAuthenticator
{
    public function __construct(
        #[\SensitiveParameter]
        public string $currentPassword,
        public string $ip,
    ) {}
}
