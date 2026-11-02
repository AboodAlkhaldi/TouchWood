<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ReturnToCheckout;

/** Checkout or the cart opened while a try may be live (payments.md §1.4 steps 8, 9). */
final readonly class ReturnToCheckout
{
    public function __construct(
        public string $storeId,
    ) {}
}
