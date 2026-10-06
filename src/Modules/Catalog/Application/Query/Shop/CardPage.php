<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * One page of cards, and where the next starts — keyset, never an offset (handoff §5.4).
 */
final readonly class CardPage
{
    /**
     * @param  list<ProductCard>  $cards
     * @param  string|null  $next  the cursor of the next page; null on the last
     */
    public function __construct(
        public array $cards,
        public ?string $next,
    ) {}
}
