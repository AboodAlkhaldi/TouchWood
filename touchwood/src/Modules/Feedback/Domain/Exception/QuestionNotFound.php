<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown question, or one of a store the staff member does not cover — the same answer for both (§7).
 */
final class QuestionNotFound extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('No such question.');
    }

    public function type(): string
    {
        return 'feedback.question_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
