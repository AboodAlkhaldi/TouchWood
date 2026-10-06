<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateAttributeValue;

final readonly class ActivateAttributeValue
{
    public function __construct(
        public string $valueId,
    ) {}
}
