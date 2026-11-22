<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use DateTimeImmutable;
use Modules\Ops\Domain\Model\AutoReplyClaim;
use Modules\Ops\Domain\Model\AutoReplySender;

/** Who the WhatsApp number's auto-reply answered, and when (ops.md §9.8 point 5: `ops.whatsapp_auto_replies`). */
interface AutoReplies
{
    /** One reply to a sender in this many hours. */
    public const int HOURS_A_SENDER = 24;

    /** At most this many replies in an hour, in total (proposed in §9.8; accepted with it). */
    public const int MOST_AN_HOUR = 100;

    /**
     * Claims the sender **before** the reply is sent, so a retried notice never replies twice: one reply to a sender
     * in 24 hours, and at most MOST_AN_HOUR in the hour, whoever they are. Two claims at once cannot both pass.
     */
    public function claim(AutoReplySender $sender, DateTimeImmutable $now): AutoReplyClaim;

    /**
     * Deletes the claims older than 24 hours.
     *
     * @return int how many went
     */
    public function prune(DateTimeImmutable $now): int;
}
