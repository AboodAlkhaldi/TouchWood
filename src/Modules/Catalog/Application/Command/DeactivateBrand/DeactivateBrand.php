<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateBrand;

/**
 * Deactivates a brand, with each of its products' fate.
 */
final readonly class DeactivateBrand
{
    /**
     * @param  string|null  $everyProduct  `HIDE` or `MOVE` — for every product without its own choice
     * @param  string|null  $moveTo  the brand they move to, with `MOVE`
     * @param  array<array-key, mixed>  $products  product id => ['choice' => …, 'move_to' => …]
     */
    public function __construct(
        public string $brandId,
        public ?string $everyProduct = null,
        public ?string $moveTo = null,
        public array $products = [],
    ) {}
}
