<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ViewCustomerWishlist;

/** A customer's wishlist as staff read it (feedback.md §1.4). */
final readonly class ViewCustomerWishlist
{
    public function __construct(
        public string $customerId,
        public string $locale,
    ) {}
}
