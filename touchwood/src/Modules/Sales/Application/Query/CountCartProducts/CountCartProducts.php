<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\CountCartProducts;

final readonly class CountCartProducts
{
    public function __construct(
        public string $storeId,
    ) {}
}
