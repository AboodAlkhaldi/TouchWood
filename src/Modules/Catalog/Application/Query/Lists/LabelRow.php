<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * A label (catalog.md §1.8, §4.4 S5): its look, and on how many products the stores show it — one
 * still shown is not deleted.
 */
final readonly class LabelRow
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public string $tone,
        public bool $active,
        public int $position,
        public int $products,
    ) {}
}
