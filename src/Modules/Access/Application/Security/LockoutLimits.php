<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

/**
 * The numbers SignInLimits works to. Staff settings are global and customer settings are that
 * store's (spec §1.8), so each side answers with its own; the counting itself is the same.
 */
interface LockoutLimits
{
    /** Wrong passwords for one account before it is locked. */
    public function lockoutAttempts(): int;

    public function lockoutMinutes(): int;

    /** Wrong passwords from one address, across accounts. */
    public function ipAttempts(): int;

    public function ipMinutes(): int;
}
