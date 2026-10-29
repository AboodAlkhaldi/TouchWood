<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

/**
 * Where a notice or a staff member's message stands (ops.md §1.7, §1.8): waiting for its time (a
 * notice only), sending in batches, sent, or cancelled before it started (a notice only). Once sending
 * starts nothing can be recalled.
 */
enum BroadcastStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Sending = 'SENDING';
    case Sent = 'SENT';
    case Cancelled = 'CANCELLED';
}
