<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Application\Alerts\EventDeliveries;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Public\Enums\MessageChannel;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each email or SMS of an event's message is its own row, written QUEUED in the listener's
 * transaction, and its own job, which names the row (ops.md §5-§7).
 */
final readonly class QueuedEventDeliveries implements EventDeliveries
{
    public function __construct(
        private Dispatcher $jobs,
        private SentMessages $sent,
    ) {}

    public function emailCustomer(EventMessage $message, string $customerId): void
    {
        $this->jobs->dispatch(new SendEventEmailJob($message, strtolower($customerId), null, $this->queued($message, MessageChannel::Email, $customerId, null)));
    }

    public function emailStaff(EventMessage $message, string $staffId): void
    {
        $this->jobs->dispatch(new SendEventEmailJob($message, null, strtolower($staffId), $this->queued($message, MessageChannel::Email, null, $staffId)));
    }

    public function smsStaff(EventMessage $message, string $staffId, StaffNotificationTopic $topic, string $permission): void
    {
        $this->jobs->dispatch(new SendEventSmsJob($message, strtolower($staffId), $topic, $permission, $this->queued($message, MessageChannel::Sms, null, $staffId)));
    }

    /** The message's row, QUEUED; its id. */
    private function queued(EventMessage $message, MessageChannel $channel, ?string $customerId, ?string $staffId): string
    {
        $id = strtolower((string) Str::ulid());

        $this->sent->record(new SentMessage(
            $id,
            $message->kind,
            $customerId === null ? null : strtolower($customerId),
            $staffId === null ? null : strtolower($staffId),
            StoreId::fromString($message->storeId),
            $channel,
            null,
            SendStatus::Queued,
            CarbonImmutable::now()->toDateTimeImmutable(),
        ));

        return $id;
    }
}
