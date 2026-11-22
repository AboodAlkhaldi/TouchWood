<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetSellingTerms;

/**
 * A product's selling terms in one store: each variant's retail and wholesale switches, and the
 * product's minimum and maximum for each mode.
 */
final readonly class SetSellingTerms
{
    /**
     * @param  array<array-key, mixed>  $modes  variant id => ['retail' => bool, 'wholesale' => bool], for the variants whose modes change
     */
    public function __construct(
        public string $storeId,
        public string $productId,
        public array $modes = [],
        public int $retailMinimum = 1,
        public ?int $retailMaximum = null,
        public ?int $wholesaleMinimum = null,
        public ?int $wholesaleMaximum = null,
    ) {}
}
