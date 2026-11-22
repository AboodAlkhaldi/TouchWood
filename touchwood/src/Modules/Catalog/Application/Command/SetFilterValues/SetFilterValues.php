<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetFilterValues;

/**
 * A product's filter values, sent whole (catalog.md amendment 3(a)): values of filter attributes,
 * several of one attribute allowed — "Suitable for: Kitchen, Bathroom".
 */
final readonly class SetFilterValues
{
    /**
     * @param  array<array-key, mixed>  $valueIds
     */
    public function __construct(
        public string $productId,
        public array $valueIds,
    ) {}
}
