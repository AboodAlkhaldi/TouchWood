<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A notice another run holds (content.md §1.7 #10, §7): one notice serves one run.
 */
final class NoticeTaken extends ContentError
{
    public function __construct()
    {
        parent::__construct('The notice serves another run.');
    }

    public function type(): string
    {
        return 'content.notice_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
