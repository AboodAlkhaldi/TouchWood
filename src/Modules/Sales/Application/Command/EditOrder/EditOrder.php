<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\EditOrder;

/** A staff edit confirmed (sales.md §1.7): the reason, the order's lines as they should be, another of the customer's addresses or none, and the gift — `KEEP`, `REMOVE` or a level's id. */
final readonly class EditOrder
{
    /**
     * @param  list<array{variantId: string, mode: string, quantity: int}>  $lines  the order's lines, gift aside
     */
    public function __construct(
        public string $orderId,
        public string $reason,
        public array $lines,
        public ?string $addressId = null,
        public string $gift = 'KEEP',
        /** At an address at another place: a carrier, and `TODAY` or `KEEP` for the fee (gap 6.1). */
        public ?string $carrierId = null,
        public ?string $shippingFee = null,
    ) {}
}
