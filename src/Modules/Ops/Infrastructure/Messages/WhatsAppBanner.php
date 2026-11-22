<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Repository\SmsCountries;
use Modules\Ops\Infrastructure\External\WhatsAppSettings;
use Modules\Platform\Public\Contracts\AdminBanner;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\AdminBannerDto;

/**
 * The red notice over every panel page while WhatsApp is not set up (ops.md §9.8 point 2, W3): "WhatsApp is not set up:
 * no codes can be sent." Shown to whoever manages the Code Countries (Super Admins), and only while a country on that
 * list names `whatsapp` as its provider and a setting a code needs is missing - a development machine on `log` has
 * nothing to say. It runs on every admin page: the settings are read first, from config, so a server that is set up
 * costs nothing; the list is remembered for a minute (`cache.banner_store`), as the gateway's banners are.
 */
final readonly class WhatsAppBanner implements AdminBanner
{
    private const string KEY = 'ops.whatsapp_banner';

    private const int REMEMBER_SECONDS = 60;

    public function __construct(
        private WhatsAppSettings $settings,
        private SmsCountries $countries,
        private StoreChoices $choices,
        private Factory $caches,
        private Config $config,
    ) {}

    public function forCurrentActor(string $locale, Closure $heldStores): array
    {
        if ($this->settings->missingForCodes() === [] || ! $this->someCountryUsesWhatsApp() || $this->choices->forJobs(OpsPermissions::SMS_COUNTRIES_MANAGE) === []) {
            return [];
        }

        return [new AdminBannerDto(
            (string) __('ops::security.not_set_up_label', [], $locale),
            (string) __('ops::security.not_set_up', [], $locale),
            true,
            null,
            null,
            true,
        )];
    }

    /** A change on the list shows at once, not a minute later. */
    public function forget(): void
    {
        rescue(fn () => $this->store()->forget(self::KEY));
    }

    private function someCountryUsesWhatsApp(): bool
    {
        return (bool) $this->store()->remember(self::KEY, self::REMEMBER_SECONDS, function (): bool {
            foreach ($this->countries->all() as $country) {
                if ($country->provider() === 'whatsapp') {
                    return true;
                }
            }

            return false;
        });
    }

    private function store(): Repository
    {
        return $this->caches->store((string) $this->config->get('cache.banner_store', 'file'));
    }
}
