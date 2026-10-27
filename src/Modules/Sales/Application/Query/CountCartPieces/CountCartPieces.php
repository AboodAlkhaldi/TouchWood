<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\CountCartPieces;

final readonly class CountCartPieces
{
    public function __construct(
        public string $storeId,
    ) {}
}
