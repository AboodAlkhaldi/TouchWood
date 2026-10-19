<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use DateTimeImmutable;
use Modules\Feedback\Public\Enums\QuestionStatus;

/**
 * One of my questions (feedback.md §1.3, §3): the answer — signed by the store asked in, `storeName`'s
 * team — and the reason when it was rejected or taken down, as soon as staff wrote them.
 */
final readonly class MyQuestionRow
{
    public function __construct(
        public string $questionId,
        public string $storeId,
        public string $storeName,
        public FeedbackCard $product,
        public string $body,
        public string $locale,
        public QuestionStatus $status,
        public ?string $answer,
        public ?DateTimeImmutable $answeredAt,
        public ?string $reason,
        public DateTimeImmutable $askedAt,
    ) {}
}
