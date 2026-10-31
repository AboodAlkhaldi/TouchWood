<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Alerts\EventSender;

/**
 * The critical SMS to one super admin (ops.md §1.6: the gateway key at 1 day, or refused), its
 * `ops.sent_messages` row already written QUEUED. It carries no phone and no text: both are read when
 * it runs, with the person's critical-SMS switch, through the alert's topic and permission. Sent, then
 * marked, as the email is (SendEventEmailJob): at least once; FAILED after the last attempt (§7).
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
        public StaffNotificationTopic $topic,
        public string $permission,
        public string $sentId,
    ) {}

    public function handle(EventSender $sender): void
    {
        $sender->smsStaff($this->message, $this->staffId, $this->topic, $this->permission, $this->sentId);
    }

    public function failed(): void
    {
        app(EventSender::class)->failed($this->sentId);
    }
}
