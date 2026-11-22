<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use DateTimeImmutable;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Platform\Public\Dto\StaffNameDto;

/** A question in a store's queue (feedback.md §1.3, §3): its asker in the public form only. */
final readonly class QuestionRow
{
    public function __construct(
        public string $questionId,
        public FeedbackCard $product,
        public AuthorName $author,
        public string $body,
        public string $locale,
        public QuestionStatus $status,
        public ?string $answer,
        public ?StaffNameDto $answeredBy,
        public ?DateTimeImmutable $answeredAt,
        public ?string $reason,
        public DateTimeImmutable $askedAt,
    ) {}
}
