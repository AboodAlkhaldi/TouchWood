<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateAttributeValue;

final readonly class DeactivateAttributeValue
{
    public function __construct(
        public string $valueId,
    ) {}
}
