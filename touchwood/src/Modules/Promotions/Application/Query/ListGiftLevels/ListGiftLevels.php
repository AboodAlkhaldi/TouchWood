<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListGiftLevels;

/** A store's gift levels, highest amount first (promotions.md §1.7, §3, "Marketing › Gifts"). */
final readonly class ListGiftLevels
{
    public function __construct(
        public string $storeId,
    ) {}
}
