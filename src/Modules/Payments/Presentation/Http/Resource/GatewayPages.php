<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Payments\Application\Gateway\OwnAddresses;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\PaymentsSettings;
use Modules\Payments\Application\Query\ViewGateway\ViewGateway;
use Modules\Payments\Application\Query\ViewGateway\ViewGatewayHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/** What the Gateway page is handed (payments.md §4.2). Never more of a key than its last 6 characters. */
final readonly class GatewayPages
{
    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private PlatformApi $platform,
        private OwnAddresses $own,
    ) {}

    /** @throws Unauthorized */
    public function page(?string $code, ViewGatewayHandler $view): GatewayPage
    {
        $locale = $this->app->getLocale() === 'en' ? 'en' : 'ar';
        $store = $this->choices->chosen($code, PaymentsPermissions::GATEWAY_MANAGE);
        $offered = $this->choices->forJobs(PaymentsPermissions::GATEWAY_MANAGE);
        $store ??= $offered[0] ?? throw new Unauthorized(PaymentsPermissions::GATEWAY_MANAGE);
        $gateway = $view->handle(new ViewGateway($store->id));
        $storeId = StoreId::fromString($store->id);
        $registered = static fn (string $mode): bool => array_filter($gateway->applePayDomains, static fn (array $row): bool => $row['domain'] === $gateway->currentDomain && $row['mode'] === $mode) !== [];

        return new GatewayPage(
            strtolower($store->code),
            $store->name->in($locale),
            $store->timezone,
            array_map(static fn (StoreDto $each): GatewayStoreData => new GatewayStoreData(strtolower($each->code), $each->name->in($locale), $each->isActive), $offered),
            $gateway->offered,
            $gateway->setUp,
            $gateway->active,
            $gateway->mode,
            $gateway->liveKey === null ? null : new GatewayKeyData(...$gateway->liveKey),
            $gateway->testKey === null ? null : new GatewayKeyData(...$gateway->testKey),
            $gateway->noticeAddresses['LIVE'],
            $gateway->noticeAddresses['TEST'],
            $gateway->currentDomain,
            $registered('LIVE'),
            $registered('TEST'),
            $this->platform->setting(PaymentsSettings::WINDOW_MINUTES, $storeId)->int(),
            $this->platform->setting(PaymentsSettings::WAIT_MINUTES, $storeId)->int(),
            $this->platform->setting(PaymentsSettings::HOLD_MINUTES, $storeId)->int(),
        );
    }

    /** The domain this deployment answers on: what Apple Pay registers. */
    public function domain(): string
    {
        return $this->own->domain();
    }
}
