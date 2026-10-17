<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sync now, Retry or unwiring in a store with no connection (sync.md §7).
 */
final class NotWired extends SyncError
{
    public function __construct()
    {
        parent::__construct('This store is not wired to a provider.');
    }

    public function type(): string
    {
        return 'sync.not_wired';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
