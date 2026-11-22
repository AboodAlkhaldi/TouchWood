<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ArchiveProduct;

final readonly class ArchiveProduct
{
    public function __construct(
        public string $productId,
    ) {}
}
