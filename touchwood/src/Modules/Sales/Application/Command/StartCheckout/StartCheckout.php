<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\StartCheckout;

/**
 * The cart of this store turned into a quote (sales.md §1.2): the delivery address, whether to use
 * points and how many (none: as many as allowed), the way to pay, and the carrier when the customer
 * chose one of those serving the address (none: the cheapest). The coupon is the cart's.
 */
final readonly class StartCheckout
{
    public function __construct(
        public string $storeId,
        public string $addressId,
        public bool $usePoints,
        public ?int $pointsAsked,
        public string $wayToPay,
        public ?string $carrierId = null,
    ) {}
}
