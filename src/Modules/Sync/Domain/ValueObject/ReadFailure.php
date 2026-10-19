<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

/**
 * **Sync's own reasons a read failed**, kept as a code and written in the reader's language on the
 * screens (sync.md §4.1.1, Q31). A read's `error` - and a failing store's `last_error`, and a failed
 * read's report entry - holds one of these, or else the provider's own reason as it came (the adapter's
 * short sentence, §2.1), which is shown as it is under "The provider said:".
 */
enum ReadFailure: string
{
    /** Unwired, or wired again, while it ran: what it wrote stays, the rest is not its. */
    case StoppedRewired = 'STOPPED_REWIRED';

    /** Running past its time: its worker went away. */
    case WorkerGone = 'WORKER_GONE';

    /** Waiting or running when the store was unwired (§1.8). */
    case Unwired = 'UNWIRED';

    /** Its retry found a read of the same kind waiting, which covers it. */
    case Superseded = 'SUPERSEDED';

    /** Failed for a reason not the provider's: the failed jobs screen holds it. */
    case JobFailed = 'JOB_FAILED';
}
