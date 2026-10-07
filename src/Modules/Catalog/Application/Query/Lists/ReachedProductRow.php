<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * A product a deactivation reaches (catalog.md §1.5, §1.6), for the fates dialog: its names, its
 * stage, and the category it sits in.
 */
final readonly class ReachedProductRow
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public string $stage,
        public ?string $categoryId,
    ) {}
}
