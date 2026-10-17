<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Retrying or dismissing a report entry that is not there (sync.md §7).
 */
final class EntryNotFound extends SyncError
{
    public function __construct()
    {
        parent::__construct('There is no such entry in the Sync report.');
    }

    public function type(): string
    {
        return 'sync.entry_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
