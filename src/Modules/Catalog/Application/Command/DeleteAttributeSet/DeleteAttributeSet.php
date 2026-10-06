<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteAttributeSet;

final readonly class DeleteAttributeSet
{
    public function __construct(
        public string $setId,
    ) {}
}
