<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Contracts;

use DateTimeImmutable;
use Modules\Feedback\Public\Dto\FavouriteCountsDto;
use Modules\Feedback\Public\Dto\FeedbackCountsDto;
use Modules\Feedback\Public\Dto\QuestionForMessageDto;
use Modules\Feedback\Public\Dto\ReviewForMessageDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Feedback offers the modules after it (feedback.md §2.5) — Ops' messages and reports
 * (ops.md §1.5, §1.10, §1.11, §2.3). Reads only, for a caller that has already asked who may see them.
 */
interface FeedbackApi
{
    /**
     * **The products saved in a store, with how many customers saved each** — Ops' Favourites report:
     * most saved first, then by product id; keyset-paged, `limit` 1 to 1000, a cursor that is not one of
     * this list's starting at the top. Every product saved there, archived or not: the caller names them
     * through Catalog. One query.
     */
    public function favouriteCounts(StoreId $store, ?string $after = null, int $limit = 100): FavouriteCountsDto;

    /** **A review as its message needs it** (`ReviewNotPublished`): its product, store, customer, state and the reason staff wrote; null for none. */
    public function reviewForMessage(string $reviewId): ?ReviewForMessageDto;

    /** **A question as its message needs it** (`QuestionAnswered`, `QuestionNotPublished`): the question, the answer, the reason; null for none. */
    public function questionForMessage(string $questionId): ?QuestionForMessageDto;

    /**
     * **The daily report's counts for a store** (ops.md §1.11 item 8): the reviews and the questions written
     * from `from` up to `to` (not included), and the questions waiting for an answer **now**. One query.
     */
    public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): FeedbackCountsDto;
}
