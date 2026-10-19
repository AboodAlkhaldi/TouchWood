<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use DateTimeImmutable;

/**
 * A wishlist item (feedback.md §1.4): unavailable when the store it was saved in is off or no longer sells
 * it — judged there, whichever store is browsed (answer 17).
 */
final readonly class WishlistItemRow
{
    public function __construct(
        public FeedbackCard $product,
        public DateTimeImmutable $savedAt,
        public bool $unavailable,
    ) {}
}
