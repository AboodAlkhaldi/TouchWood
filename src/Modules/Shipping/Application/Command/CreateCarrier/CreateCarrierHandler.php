<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\CreateCarrier;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Carrier\CarrierInputs;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNameTaken;
use Modules\Shipping\Domain\Exception\CountryNotShipped;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Model\Carrier;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\ValueObject\CarrierDetails;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

final readonly class CreateCarrierHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_MANAGE;

    public function __construct(
        private CarrierChange $change,
        private CarrierInputs $inputs,
        private CarrierRepository $carriers,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the new carrier's id
     *
     * @throws CarrierNameTaken|CountryNotShipped|InvalidShippingInput|Unauthorized
     */
    public function handle(CreateCarrier $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $details = CarrierDetails::of($command->nameAr, $command->nameEn, $command->phone, $command->website, $command->trackingPattern);
        $country = strtoupper(trim($command->countryCode));
        $id = $this->carriers->nextId();

        return $this->change->run($store->id, function () use ($id, $store, $country, $details, $command): array {
            // Read under the store's lock, which Platform's removal of a country waits for too (§1.3).
            if (! in_array($country, $this->platform->storeCountries(StoreId::fromString($store->id)), true)) {
                throw new CountryNotShipped($country);
            }

            $logo = $this->inputs->logo($command->logoMediaId);
            $this->inputs->requireFreeNames($store->id, $details);
            $positions = $this->carriers->positions($store->id, $country);
            $carrier = Carrier::create($id, $store->id, $country, $details, $logo, $command->active, $positions === [] ? 1 : max($positions) + 1);
            $this->carriers->add($carrier);

            return [$id, [ShippingAudit::entry('carrier', 'created', $id, $store->id, [], [...$carrier->snapshot(), 'position' => (string) $carrier->position])]];
        });
    }
}
