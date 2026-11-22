<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Illuminate\Cache\RateLimiter;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\TooManyRequests;

/**
 * The storefront forms that cost real work and reach nobody's account — registering, asking for a
 * reset link — are limited per address: ten an hour by default, a per-store setting (owner,
 * 2026-09-20). One machine cannot make thousands of accounts, hash thousands of passwords or send
 * thousands of emails. The key holds a hash of the address, never the address.
 *
 * Signing in is not counted here: it has its own limits, which count wrong passwords (SignInLimits).
 */
final readonly class AddressLimits
{
    public function __construct(
        private RateLimiter $limiter,
        private CustomerSecuritySettings $settings,
    ) {}

    /**
     * Counts one form sent from this address. An empty address is not counted: outside a request
     * (a console command, a test fixture) there is no connection to limit.
     *
     * @throws TooManyRequests
     */
    public function count(string $ip): void
    {
        $address = trim($ip);

        if ($address === '') {
            return;
        }

        $key = 'access:storefront-forms:'.hash('sha256', $address);

        if ($this->limiter->tooManyAttempts($key, $this->settings->addressRequestsPerHour())) {
            throw new TooManyRequests($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, 3600);
    }
}
