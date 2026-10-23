<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Shop;

use Modules\Feedback\Application\Query\AuthorName;
use Modules\Feedback\Application\Query\VariantValueRow;

/**
 * Everything a product's page shows of Feedback's (feedback.md §1.1–§1.4, §2.3): its rating — the
 * average to one decimal, half up, and the count — a page of approved reviews and of answered
 * questions, each with where to go on from, and what the person looking may do.
 */
final readonly class ProductFeedback
{
    /**
     * @param  list<array{review: ShopReview, author: AuthorName, variant: list<VariantValueRow>}>  $reviews
     * @param  list<array{question: ShopQuestion, author: AuthorName, store: string}>  $questions
     * @param  array<int, int>  $stars  each star, 5 to 1, → its approved reviews (§4.2 B1)
     */
    public function __construct(
        public ?float $average,
        public int $count,
        public array $reviews,
        public ?string $nextReview,
        public array $questions,
        public ?string $nextQuestion,
        public bool $signedIn,
        public bool $saved,
        public bool $reviewed,
        public array $stars = [],
        /** The one star the reviews are narrowed to, if any. */
        public ?int $star = null,
    ) {}
}
