<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A notice can be changed or cancelled only until it starts sending (ops.md §1.7): once sending
 * starts it cannot be recalled, and a cancelled one stays cancelled.
 */
final class NoticeAlreadyStarted extends OpsError
{
    public function __construct()
    {
        parent::__construct('This notice has started sending, or was cancelled: it can no longer be changed.');
    }

    public function type(): string
    {
        return 'ops.notice_already_started';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
