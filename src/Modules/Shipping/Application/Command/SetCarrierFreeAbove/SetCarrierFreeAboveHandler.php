<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SetCarrierFreeAbove;

use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\Unauthorized;

final readonly class SetCarrierFreeAboveHandler
{
    public const string PERMISSION = ShippingPermissions::PRICES_MANAGE;

    /** As the store-wide amount's limit (shipping.md §5; gap 4.4). */
    public const int MOST = 1000000000;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
    ) {}

    /**
     * @throws CarrierNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(SetCarrierFreeAbove $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        if ($command->amountMinor !== null && ($command->amountMinor < 0 || $command->amountMinor > self::MOST)) {
            throw new InvalidShippingInput('free_above', 'between 0 and '.self::MOST);
        }

        $this->change->run($store->id, function () use ($store, $command): array {
            $carrier = $this->carriers->byId($store->id, $command->carrierId) ?? throw new CarrierNotFound($command->carrierId);

            if ($carrier->freeAboveMinor() === $command->amountMinor) {
                return [null, []];
            }

            $was = $carrier->snapshot();
            $carrier->setFreeAbove($command->amountMinor);
            $this->carriers->update($carrier);

            return [null, [ShippingAudit::entry('carrier', 'changed', $carrier->id, $store->id, $was, $carrier->snapshot())]];
        });
    }
}
