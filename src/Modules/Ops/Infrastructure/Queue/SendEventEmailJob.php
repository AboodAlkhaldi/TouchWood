<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Alerts\EventSender;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageChannel;

/**
 * One email of an event's message (ops.md §1.5, §1.6) to a customer or a staff member, sent once by its
 * delivery id (`ops.handled_events`): a retry after it left finds the id and sends nothing. It carries
 * ids and counts only; the words are written when it runs. A provider's failure is retried; after the
 * last attempt it is recorded as failed (§7).
 */
final class SendEventEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: Brevo away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public EventMessage $message,
        public ?string $customerId,
        public ?string $staffId,
        public string $deliveryId,
    ) {}

    public function handle(EventSender $sender, HandledEvents $handled): void
    {
        $handled->once($this->deliveryId, self::class, function () use ($sender): void {
            if ($this->customerId !== null) {
                $sender->emailCustomer($this->message, $this->customerId);
            } elseif ($this->staffId !== null) {
                $sender->emailStaff($this->message, $this->staffId);
            }
        });
    }

    public function failed(): void
    {
        app(EventSender::class)->failed($this->message, $this->customerId, $this->staffId, MessageChannel::Email);
    }
}
