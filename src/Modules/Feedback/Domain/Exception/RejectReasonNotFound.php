<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** No such ready reason (feedback.md §2.4 B4). */
final class RejectReasonNotFound extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('No such ready reason.');
    }

    public function type(): string
    {
        return 'feedback.reject_reason_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
