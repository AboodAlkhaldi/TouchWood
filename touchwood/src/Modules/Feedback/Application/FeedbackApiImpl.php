<?php

declare(strict_types=1);

namespace Modules\Feedback\Application;

use DateTimeImmutable;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Feedback\Public\Dto\FavouriteCountDto;
use Modules\Feedback\Public\Dto\FavouriteCountsDto;
use Modules\Feedback\Public\Dto\FeedbackCountsDto;
use Modules\Feedback\Public\Dto\QuestionForMessageDto;
use Modules\Feedback\Public\Dto\ReviewForMessageDto;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Feedback's public reads (feedback.md §2.5): Ops' Favourites report, its messages and its daily report.
 * No permission of their own — the caller asks who may see them.
 */
final readonly class FeedbackApiImpl implements FeedbackApi
{
    /** The most products a page of `favouriteCounts` holds. */
    public const int FAVOURITES_MAX = 1000;

    public function __construct(
        private FeedbackQueries $queries,
    ) {}

    public function favouriteCounts(StoreId $store, ?string $after = null, int $limit = 100): FavouriteCountsDto
    {
        $limit = min(max($limit, 1), self::FAVOURITES_MAX);
        $rows = $this->queries->favouritesIn($store, self::cursor($after), $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new FavouriteCountsDto(
            array_map(static fn (array $row): FavouriteCountDto => new FavouriteCountDto($row[0], $row[1]), $rows),
            // The same cursor the staff list writes: the count and the product id of the last row.
            $more && $last !== null ? $last[1].':'.$last[0] : null,
        );
    }

    public function reviewForMessage(string $reviewId): ?ReviewForMessageDto
    {
        $review = $this->queries->review($reviewId);

        return $review === null ? null : new ReviewForMessageDto(
            $review->id, $review->productId, $review->variantId, $review->storeId, $review->customerId,
            $review->status, $review->rating, $review->locale, $review->reason,
        );
    }

    public function questionForMessage(string $questionId): ?QuestionForMessageDto
    {
        $question = $this->queries->question($questionId);

        return $question === null ? null : new QuestionForMessageDto(
            $question->id, $question->productId, $question->storeId, $question->customerId,
            $question->status, $question->body, $question->answer, $question->locale, $question->reason,
        );
    }

    public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): FeedbackCountsDto
    {
        [$reviews, $questions, $waiting] = $this->queries->counts($store, $from, $to);

        // A reversed or empty range wrote nothing; what waits is waiting all the same.
        return $from >= $to ? new FeedbackCountsDto(0, 0, $waiting) : new FeedbackCountsDto($reviews, $questions, $waiting);
    }

    /**
     * The count and product id a cursor holds, or none — one that is not one starts at the top.
     *
     * @return array{int, string}|null
     */
    private static function cursor(?string $after): ?array
    {
        $parts = $after === null ? [] : explode(':', strtolower(trim($after)));

        return count($parts) === 2 && ctype_digit($parts[0]) && Ulids::valid($parts[1]) ? [(int) $parts[0], $parts[1]] : null;
    }
}
