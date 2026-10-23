<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Repository;

use DateTimeImmutable;
use Modules\Feedback\Domain\Model\Review;
use Modules\Feedback\Public\Enums\ReviewMark;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Shared\Domain\ValueObject\StoreId;

/** The reviews and each product's rating (feedback.md §1.1, §1.2, §5). */
interface Reviews
{
    /** The store a review was written in — whose staff moderate it. */
    public function storeOf(string $reviewId): ?StoreId;

    /** The review, its row locked until the transaction ends. */
    public function lock(StoreId $store, string $reviewId): ?Review;

    /** Whether the customer has ever written a review of the product, whatever became of it. */
    public function written(string $customerId, string $productId): bool;

    public function create(string $reviewId, string $productId, string $variantId, string $customerId, StoreId $store, int $rating, string $body, string $locale, ReviewMark $mark, DateTimeImmutable $at): void;

    public function moderate(Review $review, ReviewStatus $status, ?string $reason, string $staffId, DateTimeImmutable $at): void;

    /** The language staff set for the review's text (§4.2 B3). */
    public function setLocale(Review $review, string $locale): void;

    /** Flips the customer's review of each product to "Returned"; one already so changes nothing. */
    public function markReturned(string $customerId, string $productId): void;

    /** Adds a rating to the product's (an approval) or takes one away (`$sign` -1, an unpublishing). */
    public function countRating(string $productId, int $rating, int $sign, DateTimeImmutable $at): void;

    /** @return array{sum: int, count: int} the product's approved ratings */
    public function rating(string $productId): array;
}
