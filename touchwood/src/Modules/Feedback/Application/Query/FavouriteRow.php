<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/** A product of a store's "Favourited products" (feedback.md §1.4): how many customers saved it there. */
final readonly class FavouriteRow
{
    public function __construct(
        public FeedbackCard $product,
        public int $savedBy,
    ) {}
}
