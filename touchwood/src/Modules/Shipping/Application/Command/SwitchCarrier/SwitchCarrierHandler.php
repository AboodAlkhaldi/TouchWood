<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SwitchCarrier;

use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\Unauthorized;

final readonly class SwitchCarrierHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_MANAGE;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
    ) {}

    /**
     * @throws CarrierNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(SwitchCarrier $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        $this->change->run($store->id, function () use ($store, $command): array {
            $carrier = $this->carriers->byId($store->id, $command->carrierId) ?? throw new CarrierNotFound($command->carrierId);

            // Switched to where it is already: nothing to change, nothing to audit.
            if ($carrier->isActive() === $command->on) {
                return [null, []];
            }

            $was = $carrier->snapshot();
            $carrier->switch($command->on);
            $this->carriers->update($carrier);

            return [null, [ShippingAudit::entry('carrier', $command->on ? 'switched_on' : 'switched_off', $carrier->id, $store->id, $was, $carrier->snapshot())]];
        });
    }
}
