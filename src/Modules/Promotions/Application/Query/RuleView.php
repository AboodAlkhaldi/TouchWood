<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

/**
 * One line of a coupon's include or exclude list, named (promotions.md §1.2): `kind` INCLUDE or
 * EXCLUDE, `target` product, category or brand. The names are null when Catalog no longer has it.
 */
final readonly class RuleView
{
    public function __construct(
        public string $kind,
        public string $target,
        public string $targetId,
        public ?string $nameAr,
        public ?string $nameEn,
    ) {}
}
