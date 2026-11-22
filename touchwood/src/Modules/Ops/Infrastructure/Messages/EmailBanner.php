<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Infrastructure\External\EmailSetup;
use Modules\Platform\Public\Contracts\AdminBanner;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\AdminBannerDto;

/**
 * The red notice over every panel page while a production server's email is not set up (owner, 2026-11-22; ops.md §7):
 * "Email is not set up: no emails can be sent." For Super Admins alone - whoever holds the reserved
 * `ops.sms_countries.manage`, as WhatsAppBanner does - and never by email, which is what is down. It runs on every
 * admin page: the environment and the settings are read first, from config, so a server that is set up, and every
 * development machine, costs nothing.
 */
final readonly class EmailBanner implements AdminBanner
{
    public function __construct(
        private Application $app,
        private Config $config,
        private StoreChoices $choices,
    ) {}

    public function forCurrentActor(string $locale, Closure $heldStores): array
    {
        if (! $this->app->environment('production') || EmailSetup::problems($this->config) === [] || $this->choices->forJobs(OpsPermissions::SMS_COUNTRIES_MANAGE) === []) {
            return [];
        }

        return [new AdminBannerDto(
            (string) __('ops::security.email_not_set_up_label', [], $locale),
            (string) __('ops::security.email_not_set_up', [], $locale),
            true,
            null,
            null,
            true,
        )];
    }
}
