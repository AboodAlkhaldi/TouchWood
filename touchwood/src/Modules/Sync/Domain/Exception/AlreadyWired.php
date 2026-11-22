<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Wiring a store that is wired (sync.md §7): its connection is changed, not added again.
 */
final class AlreadyWired extends SyncError
{
    public function __construct()
    {
        parent::__construct('This store is wired to a provider already.');
    }

    public function type(): string
    {
        return 'sync.already_wired';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
