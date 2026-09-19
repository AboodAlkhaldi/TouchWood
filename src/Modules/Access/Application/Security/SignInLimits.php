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
 *
 * Each attempt is counted before its password is checked, and given back when the password is
 * right: attempts sent at the same moment cannot all pass before any of them is counted (review of
 * step 3b).
 */
final readonly class SignInLimits
{
    public function __construct(
        private RateLimiter $limiter,
        private StaffSecuritySettings $settings,
    ) {}

    /**
     * Counts this attempt. Refused while the account or the address waits, or when as many attempts
     * as the limit are already under way.
     *
     * @throws AccountLocked
     */
    public function begin(string $email, string $ip): void
    {
        $limits = $this->limits($email, $ip);

        foreach ($limits as [$kind, $subject]) {
            $lock = $this->key($kind.'-lock', $subject);

            if ($this->limiter->tooManyAttempts($lock, 1)) {
                throw new AccountLocked($this->limiter->availableIn($lock));
            }
        }

        $counted = [];

        foreach ($limits as [$kind, $subject, $max, $minutes]) {
            $counter = $this->key($kind, $subject);
            $counted[] = [$counter, $minutes];

            if ($this->limiter->increment($counter, $minutes * 60) > $max) {
                foreach ($counted as [$given, $decay]) {
                    $this->limiter->decrement($given, $decay * 60);
                }

                throw new AccountLocked($this->limiter->availableIn($counter));
            }
        }
    }

    /**
     * The password was wrong: the attempt stays counted, and a count at its limit locks.
     *
     * @return bool whether it locked the account
     */
    public function failed(string $email, string $ip): bool
    {
        [$address, $account] = $this->limits($email, $ip);
        $this->lockAtLimit(...$address);

        return $this->lockAtLimit(...$account);
    }

    /**
     * The right password: the account's count starts again, and the address gets this attempt back.
     */
    public function succeeded(string $email, string $ip): void
    {
        [[$kind, $subject, , $minutes]] = $this->limits($email, $ip);
        $address = $this->key($kind, $subject);

        $this->limiter->clear($this->key('account', $email));

        if ($this->limiter->attempts($address) > 0) {
            $this->limiter->decrement($address, $minutes * 60);
        }
    }

    /**
     * @return array{0: array{0: string, 1: string, 2: int, 3: int}, 1: array{0: string, 1: string, 2: int, 3: int}} the address's and the account's kind, subject, limit and minutes
     */
    private function limits(string $email, string $ip): array
    {
        return [
            ['ip', $ip, $this->settings->ipAttempts(), $this->settings->ipMinutes()],
            ['account', $email, $this->settings->lockoutAttempts(), $this->settings->lockoutMinutes()],
        ];
    }

    private function lockAtLimit(string $kind, string $subject, int $max, int $minutes): bool
    {
        $counter = $this->key($kind, $subject);

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
