<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\StaffMessage;
use Modules\Ops\Domain\Repository\StaffMessages;

/** `StaffMessages` in SQL (ops.md §5: `ops.staff_messages`): written sending, as it starts at once. */
final readonly class DatabaseStaffMessages implements StaffMessages
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(StaffMessage $message): void
    {
        $this->db->table('ops.staff_messages')->insert([
            'id' => strtolower($message->id),
            'store_id' => $message->store->value,
            'sender_id' => strtolower($message->senderId),
            'audience' => $message->audience(),
            'customer_id' => $message->customerId === null ? null : strtolower($message->customerId),
            'segment_id' => $message->segmentId === null ? null : strtolower($message->segmentId),
            'title_ar' => $message->text->titleAr,
            'title_en' => $message->text->titleEn,
            'body_ar' => $message->text->bodyAr,
            'body_en' => $message->text->bodyEn,
            'by_bell' => $message->byBell,
            'by_email' => $message->byEmail,
            'status' => 'SENDING',
            'created_at' => Moment::of($message->createdAt),
        ]);
    }
}
