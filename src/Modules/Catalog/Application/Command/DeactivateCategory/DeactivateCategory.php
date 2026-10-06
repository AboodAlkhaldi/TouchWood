<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateCategory;

/**
 * Deactivates a category and everything active below it, with each of its products' fate.
 */
final readonly class DeactivateCategory
{
    /**
     * @param  string|null  $everyProduct  `HIDE`, `LEAVE` or `MOVE` — for every product without its own choice
     * @param  string|null  $moveTo  the category they move to, with `MOVE`
     * @param  array<array-key, mixed>  $products  product id => ['choice' => …, 'move_to' => …]
     */
    public function __construct(
        public string $categoryId,
        public ?string $everyProduct = null,
        public ?string $moveTo = null,
        public array $products = [],
    ) {}
}
