<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A second review of a product — whatever became of the first, a rejected one included (§1.1).
 */
final class AlreadyReviewed extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('You have already reviewed this product.');
    }

    public function type(): string
    {
        return 'feedback.already_reviewed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
