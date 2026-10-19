<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Enums;

/**
 * A product question's state (feedback.md §2.2, §4): waiting for the store's staff, answered and
 * shown, refused for good, or answered then taken down from the shop (answer 18).
 */
enum QuestionStatus: string
{
    case Pending = 'PENDING';
    case Answered = 'ANSWERED';
    case Rejected = 'REJECTED';
    case Unpublished = 'UNPUBLISHED';
}
