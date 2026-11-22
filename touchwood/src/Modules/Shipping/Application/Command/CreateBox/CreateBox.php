<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\CreateBox;

/**
 * A box added to a store's list (shipping.md §1.7): its name, inner sizes (mm) and the most weight it
 * takes (g), last in the list.
 */
final readonly class CreateBox
{
    public function __construct(
        public string $storeId,
        public string $name,
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public int $maxWeightG,
        public bool $active = true,
    ) {}
}
