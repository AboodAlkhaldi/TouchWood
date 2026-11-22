<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\MarkDelivered;

/**
 * A shipped order marked delivered by staff (sales.md §1.5): customers cannot.
 */
final readonly class MarkDelivered
{
    public function __construct(
        public string $orderId,
    ) {}
}
