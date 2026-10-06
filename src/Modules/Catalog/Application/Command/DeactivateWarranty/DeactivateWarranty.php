<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateWarranty;

final readonly class DeactivateWarranty
{
    public function __construct(
        public string $warrantyId,
    ) {}
}
