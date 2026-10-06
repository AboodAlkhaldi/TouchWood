<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateWarranty;

final readonly class ActivateWarranty
{
    public function __construct(
        public string $warrantyId,
    ) {}
}
