<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

/**
 * A wired store's connection (sync.md §4): working, or failing after a read's retries until a read
 * succeeds. Not wired is no connection at all.
 */
enum ConnectionState: string
{
    case Ok = 'OK';
    case Failing = 'FAILING';
}
