<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Shop;

/** Feedback's rows for a product's page, as read (`ShopFeedbackQueries`). */
final readonly class ShopFeedbackRows
{
    /**
     * @param  list<ShopReview>  $reviews
     * @param  list<ShopQuestion>  $questions
     * @param  array<int, int>  $stars  each star (1–5) → its approved reviews
     */
    public function __construct(
        public int $ratingSum,
        public int $ratingCount,
        public array $reviews,
        public array $questions,
        public bool $saved,
        public bool $reviewed,
        public array $stars,
    ) {}
}
