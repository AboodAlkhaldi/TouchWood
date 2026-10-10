<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListSearchesWithNoResults;

/**
 * The submitted searches that found nothing in the last twelve months, grouped (catalog.md §1.11,
 * §4.4 S7, P11): every store, or one.
 */
final readonly class ListSearchesWithNoResults
{
    public function __construct(
        public ?string $storeId = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
