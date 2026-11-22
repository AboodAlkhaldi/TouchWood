<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteDraftProduct;

final readonly class DeleteDraftProduct
{
    public function __construct(
        public string $productId,
    ) {}
}
