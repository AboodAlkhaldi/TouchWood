<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateAttribute;

final readonly class DeactivateAttribute
{
    public function __construct(
        public string $attributeId,
    ) {}
}
