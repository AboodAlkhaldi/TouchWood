<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\ChangeBox;

/**
 * A box's name, inner sizes and most weight changed (shipping.md §1.7).
 */
final readonly class ChangeBox
{
    public function __construct(
        public string $storeId,
        public string $boxId,
        public string $name,
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public int $maxWeightG,
    ) {}
}
