<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Retry on an entry a read never closes (sync.md §7; Q27): a stopped offer or an off or draft size's price
 * - staff open the offer or the product instead, or dismiss the entry.
 */
final class EntryNotRetryable extends SyncError
{
    public function __construct()
    {
        parent::__construct('A read never closes this entry: open the offer or the product instead.');
    }

    public function type(): string
    {
        return 'sync.entry_not_retryable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
