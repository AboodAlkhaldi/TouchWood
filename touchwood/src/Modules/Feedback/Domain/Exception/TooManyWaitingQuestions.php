<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A sixth question waiting, in any store (§1.3, answer 16).
 */
final class TooManyWaitingQuestions extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('Five of your questions are already waiting for an answer.');
    }

    public function type(): string
    {
        return 'feedback.too_many_waiting_questions';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
