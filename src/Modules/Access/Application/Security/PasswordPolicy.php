<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Modules\Access\Domain\Exception\PasswordTooWeak;

/**
 * A new password (spec §1.8): long enough, not found in a known data breach, then hashed. The
 * breach check sends only the first 5 characters of the password's SHA-1 hash; when the service
 * cannot be reached the password is accepted and the outage logged (owner's decision, 2026-09-19).
 */
interface PasswordPolicy
{
    /**
     * @return string the hash to store
     *
     * @throws PasswordTooWeak
     */
    public function hashNew(string $password, int $minLength): string;
}
