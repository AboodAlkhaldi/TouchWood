<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\DeleteCarrier;

use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\CarrierStillOn;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\Unauthorized;

final readonly class DeleteCarrierHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_MANAGE;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
    ) {}

    /**
     * @throws CarrierNotFound|CarrierStillOn|InvalidShippingInput|Unauthorized
     */
    public function handle(DeleteCarrier $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        $this->change->run($store->id, function () use ($store, $command): array {
            $carrier = $this->carriers->byId($store->id, $command->carrierId) ?? throw new CarrierNotFound($command->carrierId);

            if ($carrier->isActive()) {
                throw new CarrierStillOn($carrier->details()->nameAr, $carrier->details()->nameEn);
            }

            // The row goes, its price groups by their foreign key (step 4); the logo is only no
            // longer named by it - the file stays in the media library (step 3 Q7). The audit keeps
            // what it was, both names.
            $was = [...$carrier->snapshot(), 'position' => (string) $carrier->position];
            $this->carriers->delete($carrier->id);

            return [null, [ShippingAudit::entry('carrier', 'deleted', $carrier->id, $store->id, $was, [])]];
        });
    }
}
