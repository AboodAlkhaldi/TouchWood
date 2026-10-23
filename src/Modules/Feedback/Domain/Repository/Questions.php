<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Repository;

use DateTimeImmutable;
use Modules\Feedback\Domain\Model\Question;
use Shared\Domain\ValueObject\StoreId;

/** The product questions (feedback.md §1.3, §5): every staff read names the store asked in. */
interface Questions
{
    /** The store a question was asked in — whose staff answer it. */
    public function storeOf(string $questionId): ?StoreId;

    /** The question, its row locked until the transaction ends. */
    public function lock(StoreId $store, string $questionId): ?Question;

    /** How many of the customer's questions wait for an answer, in every store. */
    public function waiting(string $customerId): int;

    public function create(string $questionId, string $productId, StoreId $store, string $customerId, string $body, string $locale, DateTimeImmutable $at): void;

    public function answer(Question $question, string $answer, string $staffId, DateTimeImmutable $at): void;

    /** The answer's text changed — who answered and when stay, so it does not move on the page. */
    public function correct(Question $question, string $answer, DateTimeImmutable $at): void;

    /** The language staff set for the question — its answer is written in it (§4.2 B3). */
    public function setLocale(Question $question, string $locale, DateTimeImmutable $at): void;

    public function reject(Question $question, ?string $reason, DateTimeImmutable $at): void;

    public function unpublish(Question $question, ?string $reason, DateTimeImmutable $at): void;
}
