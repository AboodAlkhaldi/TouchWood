<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewGateway;

use Modules\Payments\Application\Gateway\GatewayAddresses;
use Modules\Payments\Application\Gateway\GatewayChange;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Enums\PaymentMode;
use Shared\Application\Unauthorized;

final readonly class ViewGatewayHandler
{
    public const string PERMISSION = PaymentsPermissions::GATEWAY_MANAGE;

    public function __construct(
        private GatewayChange $change,
        private Gateways $gateways,
    ) {}

    /**
     * @throws Unauthorized|InvalidPaymentsInput
     */
    public function handle(ViewGateway $query): GatewayView
    {
        [$store] = $this->change->authorize($query->storeId);
        $gateway = $this->gateways->of($store->id);

        return new GatewayView(
            GatewayAddresses::offered($store->countryCode),
            $gateway !== null,
            $gateway !== null && $gateway->active,
            $gateway?->mode->value,
            GatewayView::key($this->gateways->currentKey($store->id, PaymentMode::Test), $store->timezone),
            GatewayView::key($this->gateways->currentKey($store->id, PaymentMode::Live), $store->timezone),
        );
    }
}
