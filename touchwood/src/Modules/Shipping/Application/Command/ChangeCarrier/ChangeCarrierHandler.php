<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\ChangeCarrier;

use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Carrier\CarrierInputs;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNameTaken;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\ValueObject\CarrierDetails;
use Shared\Application\Unauthorized;

final readonly class ChangeCarrierHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_MANAGE;

    public function __construct(
        private CarrierChange $change,
        private CarrierInputs $inputs,
        private CarrierRepository $carriers,
    ) {}

    /**
     * @throws CarrierNameTaken|CarrierNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(ChangeCarrier $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $details = CarrierDetails::of($command->nameAr, $command->nameEn, $command->phone, $command->website, $command->trackingPattern);

        $this->change->run($store->id, function () use ($store, $details, $command): array {
            $carrier = $this->carriers->byId($store->id, $command->carrierId) ?? throw new CarrierNotFound($command->carrierId);
            $logo = $this->inputs->logo($command->logoMediaId);
            $this->inputs->requireFreeNames($store->id, $details, $carrier->id);

            $was = $carrier->snapshot();
            $carrier->change($details, $logo);

            if ($carrier->snapshot() === $was) {
                return [null, []];
            }

            $this->carriers->update($carrier);

            return [null, [ShippingAudit::entry('carrier', 'changed', $carrier->id, $store->id, $was, $carrier->snapshot())]];
        });
    }
}
