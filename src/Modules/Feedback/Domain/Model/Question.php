<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Model;

use DateTimeImmutable;
use Modules\Feedback\Public\Enums\QuestionStatus;

/** A product question as it is now (feedback.md §1.3, §4). */
final readonly class Question
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $storeId,
        public string $customerId,
        public string $body,
        public string $locale,
        public QuestionStatus $status,
        public ?string $answer,
        public ?string $answeredBy,
        public ?DateTimeImmutable $answeredAt,
        public ?string $reason,
    ) {}
}
