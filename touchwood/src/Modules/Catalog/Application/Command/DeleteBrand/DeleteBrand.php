<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteBrand;

final readonly class DeleteBrand
{
    public function __construct(
        public string $brandId,
    ) {}
}
