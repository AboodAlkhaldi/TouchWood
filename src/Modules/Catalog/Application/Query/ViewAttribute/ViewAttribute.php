<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewAttribute;

/**
 * One attribute with its values (catalog.md §4.4 S3).
 */
final readonly class ViewAttribute
{
    public function __construct(
        public string $attributeId,
    ) {}
}
