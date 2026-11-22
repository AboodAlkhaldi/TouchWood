<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Illuminate\Cache\RateLimiter;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\AppSignInsTooSoon;

/**
 * The same limits as SMS codes, for app codes (amendment 91): the app step opens at most "Codes an
 * Hour" times an hour per account, as an SMS code goes at most that often to one number. A sign-in that
 * succeeds gives them back. The key holds a hash, never the id itself.
 */
final readonly class AppCodeLimits
{
    public function __construct(
        private RateLimiter $limiter,
        private StaffSecuritySettings $settings,
    ) {}

    /**
     * @throws AppSignInsTooSoon
     */
    public function open(string $staffId): void
    {
        $key = $this->key($staffId);

        if ($this->limiter->tooManyAttempts($key, $this->settings->codesPerHour())) {
            throw new AppSignInsTooSoon($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, 3600);
    }

    public function succeeded(string $staffId): void
    {
        $this->limiter->clear($this->key($staffId));
    }

    private function key(string $staffId): string
    {
        return 'access:staff-app-sign-in:'.hash('sha256', $staffId);
    }
}
