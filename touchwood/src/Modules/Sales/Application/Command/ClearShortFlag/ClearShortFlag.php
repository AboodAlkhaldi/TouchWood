<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ClearShortFlag;

/** The order's "stock was reduced" flag cleared by staff once it is settled (sales.md §1.11). */
final readonly class ClearShortFlag
{
    public function __construct(
        public string $orderId,
    ) {}
}
