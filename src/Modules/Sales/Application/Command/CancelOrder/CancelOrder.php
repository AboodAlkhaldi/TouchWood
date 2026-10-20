<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CancelOrder;

/** An order cancelled by staff before it ships, with their words (sales.md §1.6). */
final readonly class CancelOrder
{
    public function __construct(
        public string $orderId,
        public string $reason,
    ) {}
}
