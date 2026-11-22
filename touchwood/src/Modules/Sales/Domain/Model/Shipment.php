<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/**
 * An order's one shipment as staff marked it (sales.md §1.5, §9.5 #3): the carrier — one of the store's,
 * its names as they were, or "Other carrier" typed by staff (no id, the name in both) — the tracking
 * number when there is one, and the full tracking link, made from the carrier's pattern or typed.
 */
final readonly class Shipment
{
    public function __construct(
        public ?string $carrierId,
        public string $carrierAr,
        public string $carrierEn,
        public ?string $trackingNumber,
        public ?string $trackingLink,
    ) {}
}
