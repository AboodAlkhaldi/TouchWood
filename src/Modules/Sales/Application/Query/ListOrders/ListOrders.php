<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOrders;

final readonly class ListOrders
{
    public function __construct(
        /** One store (Store orders); null every store the reader covers (All orders). */
        public ?string $storeId = null,
        /** ALL, UNPAID or SHORT. */
        public string $filter = 'ALL',
        /** The id of the last order shown. */
        public ?string $after = null,
    ) {}
}
