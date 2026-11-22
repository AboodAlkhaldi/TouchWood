<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A value in a product page's choices: a button, a colour's with its swatch; one no variant on sale
 * holds is shown disabled.
 */
final readonly class ShopChoiceValue
{
    public function __construct(
        public string $valueId,
        public string $name,
        public ?string $swatch,
        public bool $onSale,
    ) {}
}
