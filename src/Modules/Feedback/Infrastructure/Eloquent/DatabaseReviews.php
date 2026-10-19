<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Feedback\Domain\Model\Review;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Feedback\Public\Enums\ReviewMark;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/** `Reviews` in SQL, no Eloquent model (feedback.md §1.6). */
final readonly class DatabaseReviews implements Reviews
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function storeOf(string $reviewId): ?StoreId
    {
        $store = Ulids::valid($reviewId) ? $this->db->table('feedback.reviews')->where('id', strtolower($reviewId))->value('store_id') : null;

        return $store === null ? null : StoreId::fromString((string) $store);
    }

    public function lock(StoreId $store, string $reviewId): ?Review
    {
        if (! Ulids::valid($reviewId)) {
            return null;
        }

        $row = $this->db->table('feedback.reviews')->where('store_id', $store->value)->where('id', strtolower($reviewId))->lockForUpdate()->first();

        return $row instanceof stdClass ? self::toReview($row) : null;
    }

    public function written(string $customerId, string $productId): bool
    {
        return $this->db->table('feedback.reviews')->where('customer_id', $customerId)->where('product_id', $productId)->exists();
    }

    public function create(string $reviewId, string $productId, string $variantId, string $customerId, StoreId $store, int $rating, string $body, string $locale, ReviewMark $mark, DateTimeImmutable $at): void
    {
        $this->db->table('feedback.reviews')->insert([
            'id' => $reviewId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'customer_id' => $customerId,
            'store_id' => $store->value,
            'rating' => $rating,
            'body' => $body,
            'locale' => $locale,
            'status' => ReviewStatus::Pending->value,
            'mark' => $mark->value,
            'created_at' => Moment::of($at),
        ]);
    }

    public function moderate(Review $review, ReviewStatus $status, ?string $reason, string $staffId, DateTimeImmutable $at): void
    {
        $this->db->table('feedback.reviews')->where('id', $review->id)->update([
            'status' => $status->value,
            'reason' => $reason,
            'moderated_by' => $staffId,
            'moderated_at' => Moment::of($at),
        ]);
    }

    public function markReturned(string $customerId, string $productId): void
    {
        $this->db->table('feedback.reviews')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('mark', ReviewMark::VerifiedPurchase->value)
            ->update(['mark' => ReviewMark::Returned->value]);
    }

    public function countRating(string $productId, int $rating, int $sign, DateTimeImmutable $at): void
    {
        // Taken away, the rating was counted when approved: its row is there. An INSERT … ON CONFLICT
        // would be refused here, as PostgreSQL checks the row it would insert (a count of -1) first.
        if ($sign < 0) {
            $changed = $this->db->update(
                'UPDATE feedback.product_ratings SET rating_sum = rating_sum - ?, rating_count = rating_count - 1, updated_at = ? WHERE product_id = ?',
                [$rating, Moment::of($at), $productId],
            );

            if ($changed !== 1) {
                throw new LogicException("No rating of product {$productId} counted the approved review taken away.");
            }

            return;
        }

        $this->db->statement(<<<'SQL'
            INSERT INTO feedback.product_ratings (product_id, rating_sum, rating_count, updated_at)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (product_id) DO UPDATE
                SET rating_sum = feedback.product_ratings.rating_sum + EXCLUDED.rating_sum,
                    rating_count = feedback.product_ratings.rating_count + EXCLUDED.rating_count,
                    updated_at = EXCLUDED.updated_at
            SQL, [$productId, $rating, 1, Moment::of($at)]);
    }

    public function rating(string $productId): array
    {
        $row = $this->db->table('feedback.product_ratings')->where('product_id', $productId)->first(['rating_sum', 'rating_count']);

        return $row instanceof stdClass ? ['sum' => (int) $row->rating_sum, 'count' => (int) $row->rating_count] : ['sum' => 0, 'count' => 0];
    }

    private static function toReview(stdClass $row): Review
    {
        return new Review(
            (string) $row->id,
            (string) $row->product_id,
            (string) $row->variant_id,
            (string) $row->customer_id,
            (string) $row->store_id,
            (int) $row->rating,
            (string) $row->body,
            (string) $row->locale,
            ReviewStatus::from((string) $row->status),
            ReviewMark::from((string) $row->mark),
            $row->reason === null ? null : (string) $row->reason,
            $row->moderated_by === null ? null : (string) $row->moderated_by,
            $row->moderated_at === null ? null : CarbonImmutable::parse((string) $row->moderated_at),
            CarbonImmutable::parse((string) $row->created_at),
        );
    }
}
