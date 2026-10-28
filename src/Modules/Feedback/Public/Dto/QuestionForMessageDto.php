<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Dto;

use Modules\Feedback\Public\Enums\QuestionStatus;

/** A question as Ops' message about it needs it (`FeedbackApi::questionForMessage`). */
final readonly class QuestionForMessageDto
{
    public function __construct(
        public string $questionId,
        public string $productId,
        public string $storeId,
        public string $customerId,
        public QuestionStatus $status,
        public string $question,
        /** The store's answer, as corrected last; null before one. */
        public ?string $answer,
        /** The question's language. */
        public string $locale,
        /** The words staff wrote rejecting or taking it down; null when none. */
        public ?string $reason,
    ) {}
}
