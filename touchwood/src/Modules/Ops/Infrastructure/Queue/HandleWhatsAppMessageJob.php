<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Infrastructure\Messages\WhatsAppAutoReply;

/**
 * A person wrote to the WhatsApp number (ops.md §9.8 points 4-5): the auto-reply, once. Queued by the webhook, which
 * answered 200 at once.
 *
 * **Handled once, by Meta's message id** (`ops.handled_events`, as every Ops listener is): Meta may send a notice
 * again. Then the reply's own rules - one to a sender in 24 hours, 100 an hour - apply (WhatsAppAutoReply). **One
 * try**: the sender is claimed before the send, so a second run would find them answered, and a failed reply raises
 * no alert. The job carries the sender's number - the reply needs it - so its payload is **encrypted** (`ShouldBeEncrypted`, with the app key): the number never sits readable in `jobs` or, after a failure, in `failed_jobs`.
 */
final class HandleWhatsAppMessageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $messageId,
        #[\SensitiveParameter] public string $sender,
    ) {}

    public function handle(HandledEvents $handled, WhatsAppAutoReply $reply): void
    {
        $first = $handled->once(self::onceId($this->messageId), self::class, static function (): void {});

        if ($first) {
            $reply->reply($this->sender);
        }
    }

    /** The message id as the uuid `ops.handled_events` keys on. */
    public static function onceId(string $messageId): string
    {
        $hash = substr(sha1('whatsapp-message|'.$messageId), 0, 32);

        return implode('-', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)]);
    }
}
