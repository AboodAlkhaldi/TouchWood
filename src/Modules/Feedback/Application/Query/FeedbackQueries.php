<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use Modules\Feedback\Domain\Model\Question;
use Modules\Feedback\Domain\Model\Review;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Shared\Domain\ValueObject\StoreId;

/**
 * Feedback's reads in SQL (feedback.md §3): every list a keyset page — after the id of the last row
 * shown, an id that names no row reading as the end — never an offset.
 */
interface FeedbackQueries
{
    /**
     * A customer's reviews, in every store, newest first.
     *
     * @return list<Review>
     */
    public function reviewsBy(string $customerId, ?string $afterId, int $limit): array;

    /**
     * A customer's questions, in every store, newest first.
     *
     * @return list<Question>
     */
    public function questionsBy(string $customerId, ?string $afterId, int $limit): array;

    /**
     * A customer's wishlist items, newest saved first; only those of these stores when given.
     *
     * @param  list<string>|null  $storeIds
     * @return list<WishlistItem>
     */
    public function wishlistOf(string $customerId, ?array $storeIds): array;

    /**
     * The reviews written in a store in this state: a waiting queue oldest first — the next to decide
     * on top — any other newest first.
     *
     * @return list<Review>
     */
    public function reviewsIn(StoreId $store, ReviewStatus $status, ?string $afterId, int $limit): array;

    /**
     * The questions asked in a store in this state, ordered as `reviewsIn`.
     *
     * @return list<Question>
     */
    public function questionsIn(StoreId $store, QuestionStatus $status, ?string $afterId, int $limit): array;

    /**
     * The products saved in a store with how many customers saved each, most saved first, then by id;
     * after the count and id of the last row shown.
     *
     * @param  array{int, string}|null  $after
     * @return list<array{string, int}> product id and count
     */
    public function favouritesIn(StoreId $store, ?array $after, int $limit): array;
}
