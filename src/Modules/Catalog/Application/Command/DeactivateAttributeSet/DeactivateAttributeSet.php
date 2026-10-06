<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateAttributeSet;

final readonly class DeactivateAttributeSet
{
    public function __construct(
        public string $setId,
    ) {}
}
