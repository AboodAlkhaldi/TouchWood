<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

/**
 * Where a notice or a staff member's message stands (ops.md §1.7, §1.8): waiting for its time (a
 * notice only), sending in batches, sent, or cancelled before it started (a notice only). Once sending
 * starts nothing can be recalled. **Failed**: a batch failed its last attempt - it waits on the failed
 * jobs screen, and retrying it there carries on from where it stopped (a batch is safe to run again).
 * **Draft** (a notice only, §9.7 #1): no time, never sent by the scheduler; a campaign that names it starts it.
 */
enum BroadcastStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Sending = 'SENDING';
    case Sent = 'SENT';
    case Cancelled = 'CANCELLED';
    case Failed = 'FAILED';
}
