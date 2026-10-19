<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown review, or one of a store the staff member does not cover — the same answer for both (§7).
 */
final class ReviewNotFound extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('No such review.');
    }

    public function type(): string
    {
        return 'feedback.review_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
