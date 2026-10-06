<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateAttributeSet;

final readonly class ActivateAttributeSet
{
    public function __construct(
        public string $setId,
    ) {}
}
