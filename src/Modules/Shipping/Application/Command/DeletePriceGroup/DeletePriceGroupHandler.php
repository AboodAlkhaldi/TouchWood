<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\DeletePriceGroup;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Price\PriceAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Exception\PriceGroupNotFound;
use Modules\Shipping\Domain\Model\PriceGroup;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;
use Shared\Application\Unauthorized;

final readonly class DeletePriceGroupHandler
{
    public const string PERMISSION = ShippingPermissions::PRICES_MANAGE;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
        private PriceGroupRepository $groups,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws CarrierNotFound|InvalidShippingInput|PriceGroupNotFound|Unauthorized
     */
    public function handle(DeletePriceGroup $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        $this->change->run($store->id, function () use ($store, $command): array {
            $carrier = $this->carriers->byId($store->id, $command->carrierId) ?? throw new CarrierNotFound($command->carrierId);
            $groups = [];

            foreach ($this->groups->ofCarrier($carrier->id) as $group) {
                $groups[$group->id] = $group;
            }

            $groupId = strtolower(trim($command->groupId));

            if (! isset($groups[$groupId])) {
                throw new PriceGroupNotFound($groupId);
            }

            $kept = array_values(array_filter($groups, static fn (PriceGroup $group): bool => $group->id !== $groupId));
            $this->groups->saveCarrier($carrier->id, $kept);

            return [null, PriceAudit::entries($carrier, $store, $groups, $kept, $this->platform)];
        });
    }
}
