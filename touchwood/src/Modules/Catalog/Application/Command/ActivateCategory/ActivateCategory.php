<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateCategory;

final readonly class ActivateCategory
{
    public function __construct(
        public string $categoryId,
    ) {}
}
