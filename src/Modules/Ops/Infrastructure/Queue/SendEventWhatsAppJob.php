<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Alerts\EventSender;

/**
 * An order update by WhatsApp to one customer (ops.md §9.10), its `ops.sent_messages` row already written QUEUED. It
 * carries no phone and no words: the customer and the order are read again when it runs. Sent, then marked, as the
 * email is (SendEventEmailJob): at least once; FAILED after the last attempt (§7).
 */
final class SendEventWhatsAppJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: Meta away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public EventMessage $message,
        public string $customerId,
        public string $sentId,
    ) {}

    public function handle(EventSender $sender): void
    {
        $sender->whatsAppCustomer($this->message, $this->customerId, $this->sentId);
    }

    public function failed(): void
    {
        app(EventSender::class)->failed($this->sentId);
    }
}
