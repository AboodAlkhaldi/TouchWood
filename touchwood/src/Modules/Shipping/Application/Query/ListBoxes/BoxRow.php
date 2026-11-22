<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListBoxes;

/**
 * One box as the Boxes screen shows it: inner sizes in millimetres, the most weight in grams.
 */
final readonly class BoxRow
{
    public function __construct(
        public string $id,
        public string $name,
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public int $maxWeightG,
        public bool $active,
        public int $position,
    ) {}
}
