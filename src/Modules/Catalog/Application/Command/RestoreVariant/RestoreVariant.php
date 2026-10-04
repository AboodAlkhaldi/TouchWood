<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RestoreVariant;

final readonly class RestoreVariant
{
    public function __construct(
        public string $variantId,
    ) {}
}
