<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Dto;

/** A store's counts for Ops' daily report (`FeedbackApi::counts`). */
final readonly class FeedbackCountsDto
{
    public function __construct(
        /** Reviews written in the range, whatever became of them. */
        public int $newReviews,
        /** Questions asked in the range, whatever became of them. */
        public int $newQuestions,
        /** Questions of the store waiting for an answer now. */
        public int $waitingQuestions,
    ) {}
}
