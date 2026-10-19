<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ViewMyWishlist;

/** My whole wishlist — at most 500 items, so no pages (feedback.md §1.4). */
final readonly class ViewMyWishlist
{
    public function __construct(
        public string $locale,
    ) {}
}
