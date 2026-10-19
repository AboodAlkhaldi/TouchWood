<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\RemoveFromWishlist;

/** A customer takes a product saved in a store off their wishlist (feedback.md §1.4). */
final readonly class RemoveFromWishlist
{
    public function __construct(
        public string $storeId,
        public string $productId,
    ) {}
}
