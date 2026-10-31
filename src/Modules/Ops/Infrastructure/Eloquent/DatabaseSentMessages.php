<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;

/** `SentMessages` in SQL (ops.md §5: `ops.sent_messages`): no text, no address, no code, no link. */
final readonly class DatabaseSentMessages implements SentMessages
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function record(SentMessage $message): void
    {
        $this->db->table('ops.sent_messages')->insert([
            'id' => strtolower($message->id),
            'kind' => $message->kind->value,
            'customer_id' => $message->customerId === null ? null : strtolower($message->customerId),
            'staff_id' => $message->staffId === null ? null : strtolower($message->staffId),
            'store_id' => $message->store?->value,
            'channel' => $message->channel->value,
            'provider_id' => $message->providerId,
            'status' => $message->status->value,
            'created_at' => Moment::of($message->createdAt),
        ]);
    }

    public function status(string $id): ?SendStatus
    {
        $status = $this->db->table('ops.sent_messages')->where('id', strtolower($id))->value('status');

        return is_string($status) ? SendStatus::from($status) : null;
    }

    public function markSent(string $id, ?string $providerId): void
    {
        // From FAILED too: a job the queue gave up on, retried from the failed jobs screen, that went.
        $this->db->table('ops.sent_messages')->where('id', strtolower($id))->where('status', '<>', SendStatus::Sent->value)
            ->update(['status' => SendStatus::Sent->value, 'provider_id' => $providerId]);
    }

    public function markFailed(string $id): void
    {
        $this->db->table('ops.sent_messages')->where('id', strtolower($id))->where('status', SendStatus::Queued->value)
            ->update(['status' => SendStatus::Failed->value]);
    }

    public function forget(string $id): void
    {
        $this->db->table('ops.sent_messages')->where('id', strtolower($id))->where('status', SendStatus::Queued->value)->delete();
    }
}
