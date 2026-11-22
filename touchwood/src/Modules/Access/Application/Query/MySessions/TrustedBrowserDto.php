<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MySessions;

/**
 * One browser allowed to sign in with the password alone until the trust expires (spec §1.8).
 *
 * It carries no token and no hash: what is stored is a hash, and nothing on a screen needs it.
 */
final readonly class TrustedBrowserDto
{
    public function __construct(
        public string $id,
        /** ISO 8601. After this it asks for a code again on its own. */
        public string $expiresAt,
    ) {}
}
