<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListVariations;

use Modules\Catalog\Application\Query\Lists\AttributeRow;
use Modules\Catalog\Application\Query\Lists\VariationRow;

/**
 * The variations, the attributes they may be made of — to name a set's members and to pick new
 * ones — and whether the reader may change them.
 */
final readonly class VariationList
{
    /**
     * @param  list<VariationRow>  $variations
     * @param  list<AttributeRow>  $attributes
     */
    public function __construct(
        public array $variations,
        public array $attributes,
        public bool $mayChange,
    ) {}
}
