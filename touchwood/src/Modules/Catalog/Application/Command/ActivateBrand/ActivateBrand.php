<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateBrand;

final readonly class ActivateBrand
{
    public function __construct(
        public string $brandId,
    ) {}
}
