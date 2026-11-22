<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CorrectShipment;

/**
 * A shipped order's carrier, tracking number or link corrected by staff (sales.md §9.5 #3; gap 6.5): the
 * whole shipment as it should be, as when it was marked shipped.
 */
final readonly class CorrectShipment
{
    public function __construct(
        public string $orderId,
        public ?string $carrierId = null,
        public ?string $otherCarrier = null,
        public ?string $trackingNumber = null,
        public ?string $trackingLink = null,
    ) {}
}
