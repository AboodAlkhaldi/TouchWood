<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\AutoReplyClaim;
use Modules\Ops\Domain\Model\AutoReplySender;
use Modules\Ops\Domain\Repository\AutoReplies;

/** `AutoReplies` in SQL (ops.md §9.8 point 5): one row a sender, on its primary key; the hour's count on the age index. */
final readonly class DatabaseAutoReplies implements AutoReplies
{
    /** The advisory lock the hour's total is counted under, so two senders at once cannot both take the last place. */
    private const int LOCK = 7_204_100_001;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function claim(AutoReplySender $sender, DateTimeImmutable $now): AutoReplyClaim
    {
        return $this->db->transaction(function () use ($sender, $now): AutoReplyClaim {
            $this->db->select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);

            $dayAgo = Moment::of($now->modify('-'.self::HOURS_A_SENDER.' hours'));
            // The sender's own claim, once it is a day old, is no claim any more.
            $this->db->delete('DELETE FROM ops.whatsapp_auto_replies WHERE sender_hmac = ? AND replied_at <= ?::timestamptz', [$sender->hmac, $dayAgo]);

            if ($this->db->table('ops.whatsapp_auto_replies')->where('sender_hmac', $sender->hmac)->exists()) {
                return AutoReplyClaim::AlreadyAnswered;
            }

            $hourAgo = Moment::of($now->modify('-1 hour'));

            if ($this->db->table('ops.whatsapp_auto_replies')->where('replied_at', '>', $hourAgo)->count() >= self::MOST_AN_HOUR) {
                return AutoReplyClaim::CapReached;
            }

            $this->db->table('ops.whatsapp_auto_replies')->insert(['sender_hmac' => $sender->hmac, 'replied_at' => Moment::of($now)]);

            return AutoReplyClaim::Claimed;
        });
    }

    public function prune(DateTimeImmutable $now): int
    {
        return $this->db->delete('DELETE FROM ops.whatsapp_auto_replies WHERE replied_at <= ?::timestamptz', [Moment::of($now->modify('-'.self::HOURS_A_SENDER.' hours'))]);
    }
}
