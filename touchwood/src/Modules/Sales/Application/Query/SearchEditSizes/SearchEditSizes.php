<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\SearchEditSizes;

final readonly class SearchEditSizes
{
    public function __construct(
        public string $orderId,
        /** A product's name in either language, or a size's code; empty: the first sizes. */
        public string $q = '',
    ) {}
}
