<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteAttributeValue;

final readonly class DeleteAttributeValue
{
    public function __construct(
        public string $valueId,
    ) {}
}
