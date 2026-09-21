<?php

declare(strict_types=1);

namespace Modules\Access\Application\Session;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Model\TrustedBrowser;
use Modules\Access\Domain\Repository\StaffTokenRepository;

/**
 * "Trust this browser" (spec §1.8): no SMS code is asked on it for 30 days. The browser keeps a
 * random token in a cookie; only its hash is stored, tied to one staff member.
 */
final readonly class TrustedBrowsers
{
    public function __construct(
        private StaffTokenRepository $tokens,
        private StaffSecuritySettings $settings,
    ) {}

    /**
     * @return array{token: string, days: int} the cookie's value, and how long it lives
     */
    public function trust(string $staffId): array
    {
        $days = $this->settings->trustedBrowserDays();
        $issued = SecretTokens::issue();
        $browser = new TrustedBrowser(strtolower((string) Str::ulid()), $staffId, CarbonImmutable::now()->addDays($days));
        $this->tokens->addTrustedBrowser($browser, $issued['hash']);

        return ['token' => $issued['token'], 'days' => $days];
    }

    /**
     * Whether this browser's token is a live trust of this staff member; it is marked used.
     */
    public function trusts(string $staffId, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $now = CarbonImmutable::now();
        $browser = $this->tokens->trustedBrowser(SecretTokens::hash($token));

        if ($browser === null || $browser->staffId !== $staffId || $browser->isExpired($now)) {
            return false;
        }

        $this->tokens->touchTrustedBrowser($browser->id, $now);

        return true;
    }
}
