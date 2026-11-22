<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Dismiss on a failed read's entry (owner, 2026-10-18; sync.md §1.6): it closes only when a later read of the store succeeds - Retry or Sync now instead.
 */
final class EntryNotDismissable extends SyncError
{
    public function __construct()
    {
        parent::__construct('A failed read closes when a read works again.');
    }

    public function type(): string
    {
        return 'sync.entry_not_dismissable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
