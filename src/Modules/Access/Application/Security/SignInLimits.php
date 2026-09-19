<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Illuminate\Cache\RateLimiter;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\AccountLocked;

/**
 * Wrong passwords (spec §1.8, amendment 31): 5 for one account lock it for 15 minutes; 10 from one
 * IP address, across accounts, make that address wait 15 minutes. The lock starts at the last wrong
 * password, not the first. An email is counted whether or not an account has it, so a lock tells a
 * stranger nothing. Keys hold a hash, never the email or address itself.
 */
final readonly class SignInLimits
{
    public function __construct(
        private RateLimiter $limiter,
        private StaffSecuritySettings $settings,
    ) {}

    /**
     * @throws AccountLocked
     */
    public function requireOpen(string $email, string $ip): void
    {
        foreach ([$this->key('ip-lock', $ip), $this->key('account-lock', $email)] as $lock) {
            if ($this->limiter->tooManyAttempts($lock, 1)) {
                throw new AccountLocked($this->limiter->availableIn($lock));
            }
        }
    }

    /**
     * Counts a wrong password.
     *
     * @return bool whether it locked the account
     */
    public function failed(string $email, string $ip): bool
    {
        $this->count('ip', $ip, $this->settings->ipAttempts(), $this->settings->ipMinutes());

        return $this->count('account', $email, $this->settings->lockoutAttempts(), $this->settings->lockoutMinutes());
    }

    /**
     * The right password: the account's count starts again (the address's does not).
     */
    public function succeeded(string $email): void
    {
        $this->limiter->clear($this->key('account', $email));
    }

    private function count(string $kind, string $subject, int $max, int $minutes): bool
    {
        $counter = $this->key($kind, $subject);
        $this->limiter->hit($counter, $minutes * 60);

        if ($this->limiter->attempts($counter) < $max) {
            return false;
        }

        $this->limiter->clear($counter);
        $this->limiter->hit($this->key($kind.'-lock', $subject), $minutes * 60);

        return true;
    }

    private function key(string $kind, string $subject): string
    {
        return 'access:staff-sign-in:'.$kind.':'.hash('sha256', mb_strtolower(trim($subject)));
    }
}
