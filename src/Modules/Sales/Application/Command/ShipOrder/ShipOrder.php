<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ShipOrder;

/**
 * An approved order marked shipped by staff (sales.md §1.5, §9.5 #3): one of the store's carriers, or
 * "Other carrier" with its name typed; the tracking number and/or a full tracking link, when there are.
 */
final readonly class ShipOrder
{
    public function __construct(
        public string $orderId,
        public ?string $carrierId = null,
        public ?string $otherCarrier = null,
        public ?string $trackingNumber = null,
        public ?string $trackingLink = null,
    ) {}
}
