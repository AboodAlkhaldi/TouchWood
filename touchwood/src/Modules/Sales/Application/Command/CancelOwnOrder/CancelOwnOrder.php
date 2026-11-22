<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CancelOwnOrder;

/** A customer's own order cancelled before it ships: a reason from the list, or `OTHER` with their words (sales.md §1.6). */
final readonly class CancelOwnOrder
{
    public function __construct(
        public string $orderId,
        public string $reason,
        public ?string $other = null,
    ) {}
}
