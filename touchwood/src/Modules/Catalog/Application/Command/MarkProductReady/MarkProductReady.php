<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MarkProductReady;

final readonly class MarkProductReady
{
    public function __construct(
        public string $productId,
    ) {}
}
