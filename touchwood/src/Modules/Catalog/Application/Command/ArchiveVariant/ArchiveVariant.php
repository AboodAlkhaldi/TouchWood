<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ArchiveVariant;

final readonly class ArchiveVariant
{
    public function __construct(
        public string $variantId,
    ) {}
}
