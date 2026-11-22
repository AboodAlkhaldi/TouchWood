<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\OrderCarriers;

use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\Unauthorized;

final readonly class OrderCarriersHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_MANAGE;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
    ) {}

    /**
     * @throws CarrierNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(OrderCarriers $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $country = strtoupper(trim($command->countryCode));
        $sent = array_map(static fn (string $id): string => strtolower(trim($id)), $command->carrierIds);

        if (count(array_unique($sent)) !== count($sent)) {
            throw new InvalidShippingInput('carriers', 'each carrier once');
        }

        $this->change->run($store->id, function () use ($store, $country, $sent): array {
            $was = $this->carriers->positions($store->id, $country);

            // Every id sent is one of this country's carriers in this store: another store's, or
            // another country's, is not found.
            foreach ($sent as $id) {
                if (! array_key_exists($id, $was)) {
                    throw new CarrierNotFound($id);
                }
            }

            $order = [...$sent, ...array_values(array_diff(array_keys($was), $sent))];
            $now = [];
            $entries = [];

            foreach ($order as $index => $id) {
                $now[$id] = $index + 1;

                // Each carrier whose place changed; one only numbered again where it stands is not moved.
                if ($was[$id] !== $index + 1) {
                    $entries[] = ShippingAudit::entry('carrier', 'ordered', $id, $store->id, ['position' => (string) $was[$id]], ['position' => (string) ($index + 1)]);
                }
            }

            if ($entries !== []) {
                $this->carriers->writePositions($now);
            }

            return [null, $entries];
        });
    }
}
