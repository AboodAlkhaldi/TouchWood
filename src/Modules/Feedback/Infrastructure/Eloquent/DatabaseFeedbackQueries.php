<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\WishlistItem;
use Modules\Feedback\Domain\Model\Question;
use Modules\Feedback\Domain\Model\Review;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * `FeedbackQueries` in SQL (feedback.md §1.6, §5): each list on its index — "my" lists on the
 * customer's, the queues on (store, status, created_at, id), the counts on (store, product).
 */
final readonly class DatabaseFeedbackQueries implements FeedbackQueries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function reviewsBy(string $customerId, ?string $afterId, int $limit): array
    {
        $query = $this->db->table('feedback.reviews')->where('customer_id', $customerId);

        return array_map(DatabaseReviews::toReview(...), $this->page($query, 'feedback.reviews', $afterId, true, $limit));
    }

    public function questionsBy(string $customerId, ?string $afterId, int $limit): array
    {
        $query = $this->db->table('feedback.questions')->where('customer_id', $customerId);

        return array_map(DatabaseQuestions::toQuestion(...), $this->page($query, 'feedback.questions', $afterId, true, $limit));
    }

    public function wishlistOf(string $customerId, ?array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        return array_values($this->db->table('feedback.wishlist_items')
            ->where('customer_id', $customerId)
            ->when($storeIds !== null, static fn (Builder $query): Builder => $query->whereIn('store_id', $storeIds ?? []))
            ->orderByDesc('saved_at')
            ->orderBy('product_id')
            ->get()
            ->map(static fn (stdClass $row): WishlistItem => new WishlistItem((string) $row->product_id, (string) $row->store_id, CarbonImmutable::parse((string) $row->saved_at)))
            ->all());
    }

    public function reviewsIn(StoreId $store, ReviewStatus $status, ?string $afterId, int $limit): array
    {
        $query = $this->db->table('feedback.reviews')->where('store_id', $store->value)->where('status', $status->value);

        return array_map(DatabaseReviews::toReview(...), $this->page($query, 'feedback.reviews', $afterId, $status !== ReviewStatus::Pending, $limit));
    }

    public function questionsIn(StoreId $store, QuestionStatus $status, ?string $afterId, int $limit): array
    {
        $query = $this->db->table('feedback.questions')->where('store_id', $store->value)->where('status', $status->value);

        return array_map(DatabaseQuestions::toQuestion(...), $this->page($query, 'feedback.questions', $afterId, $status !== QuestionStatus::Pending, $limit));
    }

    public function favouritesIn(StoreId $store, ?array $after, int $limit): array
    {
        $query = $this->db->table('feedback.wishlist_items')
            ->where('store_id', $store->value)
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw('count(*) as saved_by');

        if ($after !== null) {
            $query->havingRaw('count(*) < ? OR (count(*) = ? AND product_id > ?)', [$after[0], $after[0], $after[1]]);
        }

        return array_values($query->orderByDesc('saved_by')->orderBy('product_id')->limit($limit)->get()
            ->map(static fn (stdClass $row): array => [(string) $row->product_id, (int) $row->saved_by])
            ->all());
    }

    public function review(string $reviewId): ?Review
    {
        $row = Ulids::valid($reviewId) ? $this->db->table('feedback.reviews')->where('id', strtolower($reviewId))->first() : null;

        return $row instanceof stdClass ? DatabaseReviews::toReview($row) : null;
    }

    public function question(string $questionId): ?Question
    {
        $row = Ulids::valid($questionId) ? $this->db->table('feedback.questions')->where('id', strtolower($questionId))->first() : null;

        return $row instanceof stdClass ? DatabaseQuestions::toQuestion($row) : null;
    }

    public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $range = [$store->value, Moment::of($from), Moment::of($to)];
        $row = $this->db->selectOne(
            'SELECT (SELECT count(*) FROM feedback.reviews WHERE store_id = ? AND created_at >= ? AND created_at < ?) AS reviews,'
            .' (SELECT count(*) FROM feedback.questions WHERE store_id = ? AND created_at >= ? AND created_at < ?) AS questions,'
            .' (SELECT count(*) FROM feedback.questions WHERE store_id = ? AND status = ?) AS waiting',
            [...$range, ...$range, $store->value, QuestionStatus::Pending->value],
        );

        return $row instanceof stdClass ? [(int) $row->reviews, (int) $row->questions, (int) $row->waiting] : [0, 0, 0];
    }

    /**
     * A keyset page on (created_at, id): after the row with this id, newest first or oldest first.
     *
     * @return list<stdClass>
     */
    private function page(Builder $query, string $table, ?string $afterId, bool $newestFirst, int $limit): array
    {
        if ($afterId !== null) {
            $after = $this->db->table($table)->where('id', $afterId)->first(['created_at', 'id']);

            if (! $after instanceof stdClass) {
                return [];
            }

            $query->whereRaw('(created_at, id) '.($newestFirst ? '<' : '>').' (?::timestamptz, ?)', [(string) $after->created_at, (string) $after->id]);
        }

        $direction = $newestFirst ? 'desc' : 'asc';

        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('created_at', $direction)->orderBy('id', $direction)->limit($limit)->get()->all();

        return $rows;
    }
}
