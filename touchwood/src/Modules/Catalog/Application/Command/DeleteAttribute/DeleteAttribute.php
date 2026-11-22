<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteAttribute;

final readonly class DeleteAttribute
{
    public function __construct(
        public string $attributeId,
    ) {}
}
