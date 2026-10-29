<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ViewCarrier;

use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Query\CarrierRow;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\Unauthorized;

final readonly class ViewCarrierHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_VIEW;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
    ) {}

    /**
     * @throws CarrierNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(ViewCarrier $query): CarrierRow
    {
        $store = $this->change->authorize(self::PERMISSION, $query->storeId);

        return CarrierRow::of($this->carriers->byId($store->id, $query->carrierId) ?? throw new CarrierNotFound($query->carrierId));
    }
}
