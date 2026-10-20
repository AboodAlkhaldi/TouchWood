<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RejectOrder;

/** An order waiting for approval rejected by staff, with a reason the customer sees (sales.md §1.4, §1.6). */
final readonly class RejectOrder
{
    public function __construct(
        public string $orderId,
        public string $reason,
    ) {}
}
