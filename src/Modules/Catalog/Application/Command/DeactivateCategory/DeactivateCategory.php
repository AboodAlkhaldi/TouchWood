<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateCategory;

final readonly class DeactivateCategory
{
    public function __construct(
        public string $categoryId,
    ) {}
}
