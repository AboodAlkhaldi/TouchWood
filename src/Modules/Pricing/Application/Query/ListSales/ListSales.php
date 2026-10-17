<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

/**
 * A page of a store's sales (pricing.md §3), the latest start first.
 */
final readonly class ListSales
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public int $page = 1,
    ) {}
}
