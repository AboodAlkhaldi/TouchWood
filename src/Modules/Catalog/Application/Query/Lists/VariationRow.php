<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * An attribute set, which the screens call a variation (catalog.md §1.7, §4.4 S4): its attributes in
 * order, whether variants are built on it — its attributes then stay (amendment 3(k)) — and how many
 * products take it, for Delete.
 */
final readonly class VariationRow
{
    /**
     * @param  list<string>  $attributeIds  in the set's order
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $active,
        public array $attributeIds,
        public bool $builtOn,
        public int $products,
    ) {}
}
