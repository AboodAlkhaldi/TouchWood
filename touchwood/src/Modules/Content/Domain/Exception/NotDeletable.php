<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting something published, scheduled or live (content.md §1.1 rule 11, §7): unpublish it, or move it
 * back to draft, first.
 */
final class NotDeletable extends ContentError
{
    public function __construct()
    {
        parent::__construct('Only a draft can be deleted.');
    }

    public function type(): string
    {
        return 'content.not_deletable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
