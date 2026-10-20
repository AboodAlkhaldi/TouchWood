<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ApproveOrder;

/** An order waiting for approval approved by staff (sales.md §1.4). */
final readonly class ApproveOrder
{
    public function __construct(
        public string $orderId,
    ) {}
}
