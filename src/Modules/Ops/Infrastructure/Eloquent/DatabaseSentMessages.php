<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
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
}
