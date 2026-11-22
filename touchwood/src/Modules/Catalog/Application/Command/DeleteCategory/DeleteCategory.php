<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteCategory;

final readonly class DeleteCategory
{
    public function __construct(
        public string $categoryId,
    ) {}
}
