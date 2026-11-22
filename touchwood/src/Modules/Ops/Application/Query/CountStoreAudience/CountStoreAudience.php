<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\CountStoreAudience;

/** How many customers a notice or a message to every customer of a store would reach (ops.md §4.1). */
final readonly class CountStoreAudience
{
    public function __construct(
        public string $storeId,
    ) {}
}
