<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Platform\Public\Contracts\AdminBanner;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\AdminBannerDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * The gateway's banners (payments.md §1.2, §1.6, §9.12 Q18): a store in Test on every page, for staff who hold that
 * store; a key expiring or refused on Home, for those who manage the gateway. What is switched on is
 * remembered for a minute, off the database (`cache.banner_store`): it is asked on every admin page.
 */
final readonly class GatewayBanners implements AdminBanner
{
    private const string KEY = 'payments.gateway_banners';

    private const int REMEMBER_SECONDS = 60;

    public function __construct(
        private Gateways $gateways,
        private PlatformApi $platform,
        private StoreChoices $choices,
        private Factory $caches,
        private Config $config,
    ) {}

    public function forCurrentActor(string $locale, Closure $heldStores): array
    {
        $facts = $this->facts();

        if ($facts['test'] === [] && $facts['keys'] === []) {
            return [];
        }

        $banners = [];

        if ($facts['test'] !== []) {
            $names = [];

            foreach ($heldStores() as $store) {
                if (in_array($store->id, $facts['test'], true)) {
                    $names[] = self::isolated($store->name->in($locale));
                }
            }

            if ($names !== []) {
                $banners[] = new AdminBannerDto(
                    (string) __('payments::admin_gateway.banner.test_label', [], $locale),
                    (string) __('payments::admin_gateway.banner.test', ['stores' => implode(', ', $names)], $locale),
                    true,
                );
            }
        }

        if ($facts['keys'] !== []) {
            $managed = [];

            foreach ($this->choices->forJobs(PaymentsPermissions::GATEWAY_MANAGE) as $store) {
                $managed[$store->id] = $store;
            }

            foreach ($facts['keys'] as $key) {
                $store = $managed[$key['store']] ?? null;

                if ($store === null) {
                    continue;
                }

                $words = $key['rejected'] ? 'refused' : ($key['days'] < 0 ? 'expired' : ($key['days'] === 0 ? 'expiring_today' : ($key['days'] === 1 ? 'expiring_one' : 'expiring')));
                $banners[] = new AdminBannerDto(
                    (string) __('payments::admin_gateway.banner.key_label', [], $locale),
                    (string) __("payments::admin_gateway.banner.{$words}", ['store' => self::isolated($store->name->in($locale)), 'mode' => __('payments::admin_gateway.mode.'.$key['mode'], [], $locale), 'days' => $key['days']], $locale),
                    false,
                    (string) __('payments::admin_gateway.banner.open', [], $locale),
                    '/admin/payments/gateway?store='.strtolower($store->code),
                );
            }
        }

        return $banners;
    }

    /** A name inside a sentence, its direction its own: a Latin name in an Arabic sentence keeps the punctuation in place. */
    private static function isolated(string $name): string
    {
        return "\u{2068}{$name}\u{2069}";
    }

    /** A change just saved shows at once, not a minute later. */
    public function forget(): void
    {
        rescue(fn () => $this->store()->forget(self::KEY));
    }

    /** @return array{test: list<string>, keys: list<array{store: string, mode: string, days: int, rejected: bool}>} */
    private function facts(): array
    {
        /** @var array{test: list<string>, keys: list<array{store: string, mode: string, days: int, rejected: bool}>} $facts */
        $facts = $this->store()->remember(self::KEY, self::REMEMBER_SECONDS, function (): array {
            $test = [];
            $modes = [];
            $keys = [];

            foreach ($this->gateways->switchedOn() as $gateway) {
                $modes[$gateway->storeId] = $gateway->mode->value;

                if ($gateway->mode->value === 'TEST') {
                    $test[] = $gateway->storeId;
                }
            }

            // Every current key, on or off, either mode, as the warnings go (KeyExpiryWarnings): a Live key dying
            // while a store is still in Test is exactly what launch day must not find out. A date already past
            // only for the key in use: an old Test key shouldn't sit over a live store's Home for ever.
            foreach ($this->gateways->currentKeys() as $key) {
                $store = $this->platform->store(StoreId::fromString($key->storeId));

                if ($store === null) {
                    continue;
                }

                $days = KeyExpiryWarnings::daysLeft($key->expiresOn, $store->timezone);
                $inUse = ($modes[$key->storeId] ?? null) === $key->mode->value;

                if ($key->rejected || ($days >= 0 && $days <= KeyExpiryWarnings::THRESHOLDS[0]) || ($days < 0 && $inUse)) {
                    $keys[] = ['store' => $key->storeId, 'mode' => $key->mode->value, 'days' => $days, 'rejected' => $key->rejected];
                }
            }

            return ['test' => $test, 'keys' => $keys];
        });

        return $facts;
    }

    private function store(): Repository
    {
        return $this->caches->store((string) $this->config->get('cache.banner_store', 'file'));
    }
}
