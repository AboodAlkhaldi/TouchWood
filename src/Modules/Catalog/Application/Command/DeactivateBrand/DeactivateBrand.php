<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateBrand;

final readonly class DeactivateBrand
{
    public function __construct(
        public string $brandId,
    ) {}
}
