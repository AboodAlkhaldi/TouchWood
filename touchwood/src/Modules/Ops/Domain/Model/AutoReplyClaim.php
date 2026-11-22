<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

/** What claiming a sender for the auto-reply came to (ops.md §9.8 point 5). */
enum AutoReplyClaim
{
    /** This sender may be answered now: the claim is theirs, taken before the send. */
    case Claimed;

    /** They were answered in the last 24 hours: nothing is sent. */
    case AlreadyAnswered;

    /** The hour's total is reached: nothing is sent, and nobody is told. */
    case CapReached;
}
