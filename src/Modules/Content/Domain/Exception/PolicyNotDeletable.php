<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a policy that was ever published (owner, 2026-11-20): every version a customer may have accepted is kept,
 * so it can only be unpublished. A policy never published is deleted as a draft.
 */
final class PolicyNotDeletable extends ContentError
{
    public function __construct()
    {
        parent::__construct('A policy that was published is never deleted: unpublish it.');
    }

    public function type(): string
    {
        return 'content.policy_not_deletable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
