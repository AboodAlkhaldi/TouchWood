<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RestoreProduct;

final readonly class RestoreProduct
{
    public function __construct(
        public string $productId,
    ) {}
}
