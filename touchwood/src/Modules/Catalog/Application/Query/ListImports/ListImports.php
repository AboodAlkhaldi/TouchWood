<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListImports;

/**
 * The products files uploaded, newest first (catalog.md §1.12: each keeps its page).
 */
final readonly class ListImports
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
