<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * One carrier that can take the parcel (sales.md §2.4): its name, its fee — free thresholds applied —
 * and its tracking address with `{number}` for the tracking number, if it has one (owner, 2026-10-17:
 * the tracking link is optional). Provisional.
 */
final readonly class ShippingOptionDto
{
    public function __construct(
        public string $carrierCode,
        public string $nameAr,
        public string $nameEn,
        public Money $fee,
        public ?string $trackingUrlTemplate = null,
    ) {}
}
