<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Modules\Feedback\Application\Shop\ShopFeedbackQueries;
use Modules\Feedback\Application\Shop\ShopFeedbackRows;
use Modules\Feedback\Application\Shop\ShopQuestion;
use Modules\Feedback\Application\Shop\ShopReview;
use Modules\Feedback\Public\Enums\ReviewMark;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `ShopFeedbackQueries` in **one statement** (feedback.md §8: "a product page fills Feedback's
 * sections in a fixed number of queries, however many reviews"): the rating, a page of approved
 * reviews on (product, status, created_at DESC, id), a page of answered questions on (product,
 * status, answered_at DESC, id), and — for a signed-in customer — whether they saved it here and
 * whether they reviewed it.
 */
final readonly class DatabaseShopFeedbackQueries implements ShopFeedbackQueries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function forProduct(string $productId, StoreId $store, ?string $customerId, ?string $reviewsAfter, ?string $questionsAfter, int $limit, ?int $star = null): ShopFeedbackRows
    {
        $row = $this->db->selectOne(<<<'SQL'
            SELECT
                (SELECT r.rating_sum FROM feedback.product_ratings r WHERE r.product_id = :product) AS rating_sum,
                (SELECT r.rating_count FROM feedback.product_ratings r WHERE r.product_id = :product2) AS rating_count,
                (SELECT coalesce(json_object_agg(s.rating, s.reviews), '{}'::json) FROM (
                    SELECT b.rating, count(*) AS reviews FROM feedback.reviews b
                    WHERE b.product_id = :product7 AND b.status = 'APPROVED' GROUP BY b.rating
                ) s) AS stars,
                (SELECT coalesce(json_agg(page ORDER BY page.created_at DESC, page.id DESC), '[]'::json) FROM (
                    SELECT v.id, v.customer_id, v.variant_id, v.rating, v.body, v.locale, v.mark, v.created_at
                    FROM feedback.reviews v
                    WHERE v.product_id = :product3 AND v.status = 'APPROVED' AND (CAST(:star AS integer) IS NULL OR v.rating = :star2)
                      AND (CAST(:reviews_after AS text) IS NULL OR (v.created_at, v.id) < (SELECT a.created_at, a.id FROM feedback.reviews a WHERE a.id = :reviews_after2))
                    ORDER BY v.created_at DESC, v.id DESC
                    LIMIT :limit
                ) page) AS reviews,
                (SELECT coalesce(json_agg(page ORDER BY page.answered_at DESC, page.id DESC), '[]'::json) FROM (
                    SELECT q.id, q.customer_id, q.store_id, q.body, q.locale, q.answer, q.answered_at
                    FROM feedback.questions q
                    WHERE q.product_id = :product4 AND q.status = 'ANSWERED'
                      AND (CAST(:questions_after AS text) IS NULL OR (q.answered_at, q.id) < (SELECT a.answered_at, a.id FROM feedback.questions a WHERE a.id = :questions_after2 AND a.answered_at IS NOT NULL))
                    ORDER BY q.answered_at DESC, q.id DESC
                    LIMIT :limit2
                ) page) AS questions,
                EXISTS (SELECT 1 FROM feedback.wishlist_items w WHERE w.customer_id = :customer AND w.product_id = :product5 AND w.store_id = :store) AS saved,
                EXISTS (SELECT 1 FROM feedback.reviews m WHERE m.customer_id = :customer2 AND m.product_id = :product6) AS reviewed
            SQL, [
            'product' => $productId, 'product2' => $productId, 'product3' => $productId, 'product4' => $productId, 'product5' => $productId, 'product6' => $productId,
            'product7' => $productId, 'star' => $star, 'star2' => $star,
            'reviews_after' => $reviewsAfter, 'reviews_after2' => $reviewsAfter,
            'questions_after' => $questionsAfter, 'questions_after2' => $questionsAfter,
            'limit' => $limit, 'limit2' => $limit,
            'customer' => $customerId ?? '', 'customer2' => $customerId ?? '', 'store' => $store->value,
        ]);

        if (! $row instanceof stdClass) {
            return new ShopFeedbackRows(0, 0, [], [], false, false, self::stars('{}'));
        }

        return new ShopFeedbackRows(
            (int) ($row->rating_sum ?? 0),
            (int) ($row->rating_count ?? 0),
            array_map(static fn (array $review): ShopReview => new ShopReview(
                (string) $review['id'],
                (string) $review['customer_id'],
                (string) $review['variant_id'],
                (int) $review['rating'],
                (string) $review['body'],
                (string) $review['locale'],
                ReviewMark::from((string) $review['mark']),
                CarbonImmutable::parse((string) $review['created_at']),
            ), self::json($row->reviews)),
            array_map(static fn (array $question): ShopQuestion => new ShopQuestion(
                (string) $question['id'],
                (string) $question['customer_id'],
                (string) $question['store_id'],
                (string) $question['body'],
                (string) $question['locale'],
                (string) $question['answer'],
                CarbonImmutable::parse((string) $question['answered_at']),
            ), self::json($row->questions)),
            $customerId !== null && (bool) $row->saved,
            $customerId !== null && (bool) $row->reviewed,
            self::stars($row->stars),
        );
    }

    /**
     * Every star, 5 to 1, with its approved reviews — 0 where none.
     *
     * @return array<int, int>
     *
     * @throws JsonException
     */
    private static function stars(mixed $text): array
    {
        /** @var array<array-key, int|string> $counts */
        $counts = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);
        $stars = [];

        foreach ([5, 4, 3, 2, 1] as $star) {
            $stars[$star] = (int) ($counts[$star] ?? 0);
        }

        return $stars;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private static function json(mixed $text): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);

        return $rows;
    }
}
