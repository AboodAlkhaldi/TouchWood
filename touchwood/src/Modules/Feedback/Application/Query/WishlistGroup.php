<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/** A wishlist's items of one store (feedback.md §1.4), newest saved first. */
final readonly class WishlistGroup
{
    /**
     * @param  list<WishlistItemRow>  $items
     */
    public function __construct(
        public string $storeId,
        public string $storeName,
        public bool $storeOpen,
        public array $items,
    ) {}
}
