<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MakeBrandDefault;

final readonly class MakeBrandDefault
{
    public function __construct(
        public string $brandId,
    ) {}
}
