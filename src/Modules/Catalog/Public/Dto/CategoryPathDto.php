<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * **A category and the path to it** (catalog.md amendment 23(c)): its names, whether it is switched on,
 * and the names from the top of the tree down to it, itself last ("Kitchens › Drawers").
 */
final readonly class CategoryPathDto
{
    /**
     * @param  non-empty-list<TranslatedTextDto>  $path  from the root to the category, itself included
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $isActive,
        public array $path,
    ) {}
}
