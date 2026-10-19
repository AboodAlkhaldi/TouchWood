<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\SaveToWishlist;

/** A signed-in customer saves a product to their wishlist, in the store being browsed (feedback.md §1.4). */
final readonly class SaveToWishlist
{
    public function __construct(
        public string $storeId,
        public string $productId,
    ) {}
}
