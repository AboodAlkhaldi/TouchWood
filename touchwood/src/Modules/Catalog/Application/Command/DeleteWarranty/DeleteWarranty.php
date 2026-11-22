<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteWarranty;

final readonly class DeleteWarranty
{
    public function __construct(
        public string $warrantyId,
    ) {}
}
