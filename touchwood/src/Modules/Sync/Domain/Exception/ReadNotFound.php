<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A read's page asked for a read that is not there (sync.md §9.3 #19) - pruned after 90 days, or never one.
 */
final class ReadNotFound extends SyncError
{
    public function __construct()
    {
        parent::__construct('There is no such read.');
    }

    public function type(): string
    {
        return 'sync.read_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
