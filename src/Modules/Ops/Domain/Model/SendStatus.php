<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

/** Where a sent message stands (ops.md §5): waiting on the queue, handed to the provider, or failed for good. */
enum SendStatus: string
{
    case Queued = 'QUEUED';
    case Sent = 'SENT';
    case Failed = 'FAILED';
}
