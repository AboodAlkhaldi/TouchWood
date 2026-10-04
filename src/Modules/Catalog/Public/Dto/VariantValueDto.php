<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * One value a variant is made of, named in both languages, so an order keeps what it was placed
 * with (catalog.md §1.2).
 */
final readonly class VariantValueDto
{
    public function __construct(
        public string $attributeId,
        public TranslatedTextDto $attribute,
        public string $valueId,
        public TranslatedTextDto $value,
    ) {}
}
