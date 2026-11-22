<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Enums;

/**
 * A review's place in moderation (feedback.md §2.2, §4): waiting, shown, refused for good, or taken
 * down after being shown.
 */
enum ReviewStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Unpublished = 'UNPUBLISHED';
}
