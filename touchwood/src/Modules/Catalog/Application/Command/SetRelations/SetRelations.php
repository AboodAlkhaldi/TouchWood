<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetRelations;

/**
 * A product's hand-picked "Related" or "Goes with" products, sent whole and in order (catalog.md
 * §1.10): `RELATED` or `GOES_WITH`.
 */
final readonly class SetRelations
{
    /**
     * @param  array<array-key, mixed>  $productIds  in order
     */
    public function __construct(
        public string $productId,
        public string $kind,
        public array $productIds,
    ) {}
}
