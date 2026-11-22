<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Retry on an entry whose size was deleted since (owner, 2026-10-18): staff dismiss it instead.
 */
final class SizeNotFound extends SyncError
{
    public function __construct()
    {
        parent::__construct('This size no longer exists.');
    }

    public function type(): string
    {
        return 'sync.size_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
