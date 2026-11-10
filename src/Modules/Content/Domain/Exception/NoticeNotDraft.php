<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A notice that is no longer a draft of this store, read through Ops' port (content.md §1.7 #10, §7).
 */
final class NoticeNotDraft extends ContentError
{
    public function __construct()
    {
        parent::__construct('The notice is no longer a draft of this store.');
    }

    public function type(): string
    {
        return 'content.notice_not_draft';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
