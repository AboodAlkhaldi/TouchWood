<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Saving a connection whose test read failed (sync.md §1.2, §7; owner, 2026-10-13): nothing is saved,
 * the reason named - never the key or the secret.
 */
final class ConnectionFailed extends SyncError
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The provider could not be read: {$reason}");
    }

    public function type(): string
    {
        return 'sync.connection_failed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
