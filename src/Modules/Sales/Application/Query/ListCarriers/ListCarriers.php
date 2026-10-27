<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListCarriers;

final readonly class ListCarriers
{
    public function __construct(
        public string $storeId,
    ) {}
}
