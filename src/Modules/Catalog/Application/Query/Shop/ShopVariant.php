<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A variant a shopper can order in this store — **never its code** (amendment 5(d)).
 */
final readonly class ShopVariant
{
    /**
     * @param  list<VariantChoice>  $values  in the attribute set's order
     */
    public function __construct(
        public string $variantId,
        public array $values,
    ) {}
}
