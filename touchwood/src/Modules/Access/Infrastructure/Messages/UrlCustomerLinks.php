<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Messages;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Routing\UrlGenerator;
use LogicException;
use Modules\Access\Application\Customer\CustomerLinks;

/**
 * A signed storefront link, so the address it verifies needs no table (spec §5.2). Built on
 * APP_URL, never on the request's Host header: a forged host would otherwise send a real customer's
 * link to another site (review of step 3b). A link signed for APP_URL also fails its check when it
 * arrives under another host, so both ends hold.
 */
final readonly class UrlCustomerLinks implements CustomerLinks
{
    public function __construct(
        private UrlGenerator $urls,
        private Config $config,
    ) {}

    public function emailVerification(string $customerId, string $storeCode, string $locale, DateTimeImmutable $expiresAt): string
    {
        return $this->onAppUrl(fn (): string => $this->urls->temporarySignedRoute(
            'storefront.account.verify-email',
            $expiresAt,
            ['store' => $storeCode, 'locale' => $locale, 'customer' => $customerId],
        ));
    }

    public function passwordReset(string $token, string $storeCode, string $locale): string
    {
        return $this->onAppUrl(fn (): string => $this->urls->route(
            'storefront.account.reset-password',
            ['store' => $storeCode, 'locale' => $locale, 'token' => $token],
        ));
    }

    /**
     * @param  callable(): string  $build
     */
    private function onAppUrl(callable $build): string
    {
        $root = $this->config->get('app.url');

        if (! is_string($root) || $root === '') {
            throw new LogicException('APP_URL must be set: every customer link is built on it.');
        }

        $scheme = parse_url($root, PHP_URL_SCHEME);
        $this->urls->forceRootUrl($root);
        // The scheme too, or the request's would replace it: a link signed as http and opened as
        // https fails its own signature check (behind a TLS proxy, every request looks like http).
        $this->urls->forceScheme(is_string($scheme) ? $scheme : null);

        try {
            return $build();
        } finally {
            $this->urls->forceRootUrl(null);
            $this->urls->forceScheme(null);
        }
    }
}
