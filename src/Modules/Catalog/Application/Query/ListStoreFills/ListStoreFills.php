<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListStoreFills;

/**
 * A store's files, newest first (catalog.md §1.3; amendment 6(g)).
 */
final readonly class ListStoreFills
{
    public function __construct(
        public string $storeId,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
