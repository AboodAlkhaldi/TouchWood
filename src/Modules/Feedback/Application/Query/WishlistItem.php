<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use DateTimeImmutable;

/** A wishlist item as stored: a product saved in a store (feedback.md §1.4). */
final readonly class WishlistItem
{
    public function __construct(
        public string $productId,
        public string $storeId,
        public DateTimeImmutable $savedAt,
    ) {}
}
