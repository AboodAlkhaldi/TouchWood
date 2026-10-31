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
 * The critical SMS to one super admin (ops.md §1.6: the gateway key at 1 day, or refused), sent once by
 * its delivery id. It carries no phone and no text: both are read when it runs. A provider's failure is
 * retried; after the last attempt it is recorded as failed (§7).
 */
final class SendEventSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: the provider away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public EventMessage $message,
        public string $staffId,
        public string $deliveryId,
    ) {}

    public function handle(EventSender $sender, HandledEvents $handled): void
    {
        $handled->once($this->deliveryId, self::class, fn () => $sender->smsStaff($this->message, $this->staffId));
    }

    public function failed(): void
    {
        app(EventSender::class)->failed($this->message, null, $this->staffId, MessageChannel::Sms);
    }
}
