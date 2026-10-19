<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change its state does not allow (§4, §7): approving a rejected review, answering a rejected
 * question, unpublishing one not published, repeating an approval (answer 20).
 */
final class InvalidModeration extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('Not allowed in its current state.');
    }

    public function type(): string
    {
        return 'feedback.invalid_moderation';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
