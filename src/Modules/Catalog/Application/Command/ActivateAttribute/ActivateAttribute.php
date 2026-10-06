<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateAttribute;

final readonly class ActivateAttribute
{
    public function __construct(
        public string $attributeId,
    ) {}
}
