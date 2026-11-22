<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\SetShippingFee;

/**
 * A "shipping fee to be set" order's fee set by staff (sales.md §9.5 #12; gap 6.2): the amount in the
 * currency's smallest unit — 0 is a real fee — and the reason, as every edit has one.
 */
final readonly class SetShippingFee
{
    public function __construct(
        public string $orderId,
        public int $amountMinor,
        public string $reason,
    ) {}
}
