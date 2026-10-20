<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ShipOrder;

/**
 * An approved order marked shipped by staff (sales.md §1.5): the carrier, and the tracking number when there is one.
 */
final readonly class ShipOrder
{
    public function __construct(
        public string $orderId,
        public string $carrier,
        public ?string $trackingNumber = null,
    ) {}
}
