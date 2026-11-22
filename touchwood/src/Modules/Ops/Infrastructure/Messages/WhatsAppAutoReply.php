<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;
use LogicException;
use Modules\Ops\Domain\Model\AutoReplyClaim;
use Modules\Ops\Domain\Model\AutoReplySender;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\AutoReplies;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Infrastructure\External\WhatsAppClient;
use Modules\Ops\Infrastructure\External\WhatsAppSettings;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Throwable;

/**
 * The auto-reply of the WhatsApp number (ops.md §9.8 point 5; D5, W5): the number only sends codes and cannot read
 * replies, so a person who writes to it is pointed to the main number, once.
 *
 * - **One reply to a sender in 24 hours**, and **at most 100 an hour in total**, so a flood of senders cannot spend
 *   money or wear down the number's quality rating, which the codes depend on (AutoReplies).
 * - **The sender is claimed before the send**, so a notice Meta sends again never replies twice. A send that fails is
 *   not tried again either: the job has one try, and the sender stays claimed.
 * - **The sender is kept only as a keyed HMAC** of the number, the key from APP_KEY: a plain hash of a phone number
 *   could be reversed by trying every number. The number itself is never written down here.
 * - **The words are the approved ones** (W5), Arabic then English in one message - nothing is known of the sender's
 *   language - with the wa.me link of WHATSAPP_MAIN_NUMBER. Sent as a free-form text, inside the 24 hours Meta then
 *   allows.
 * - It writes its own `ops.sent_messages` row (WHATSAPP, kind `auto_reply`), and **raises no alert** when it fails:
 *   a person writing to a number that cannot read is not a fault of ours.
 */
final readonly class WhatsAppAutoReply
{
    public function __construct(
        private AutoReplies $replies,
        private SentMessages $sent,
        private WhatsAppClient $client,
        private WhatsAppSettings $settings,
        private Translator $translator,
        private Config $config,
    ) {}

    /** What a number is kept as: its digits, then the keyed HMAC. */
    public function senderOf(#[\SensitiveParameter] string $phone): AutoReplySender
    {
        return new AutoReplySender(hash_hmac('sha256', preg_replace('/\D/', '', $phone) ?? '', $this->key()));
    }

    public function reply(#[\SensitiveParameter] string $phone): void
    {
        $now = CarbonImmutable::now();

        // Nothing to point to, or no way to send: nothing is said (no alert), and nobody's 24 hours are spent.
        if ($this->settings->missingForReply() !== []) {
            return;
        }

        if ($this->replies->claim($this->senderOf($phone), $now->toDateTimeImmutable()) !== AutoReplyClaim::Claimed) {
            return;
        }

        $id = strtolower((string) Str::ulid());
        $this->sent->record(new SentMessage($id, MessageKind::AutoReply, null, null, null, MessageChannel::Whatsapp, null, SendStatus::Queued, $now->toDateTimeImmutable()));

        try {
            $providerId = $this->client->sendText($phone, $this->words());
        } catch (Throwable) {
            $this->sent->markFailed($id);

            return;
        }

        $this->sent->markSent($id, $providerId);
    }

    /** Arabic, a blank line, English: the link is the same in both. */
    private function words(): string
    {
        $link = 'https://wa.me/'.$this->settings->mainNumber();

        return (string) $this->translator->get('ops::security.auto_reply', ['link' => $link], 'ar')."\n\n".(string) $this->translator->get('ops::security.auto_reply', ['link' => $link], 'en');
    }

    private function key(): string
    {
        $key = $this->config->get('app.key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('APP_KEY must be set: the auto-reply keeps senders as a keyed HMAC.');
        }

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false || $decoded === '') {
            throw new LogicException('APP_KEY is not valid base64: the auto-reply will not key its HMAC with nothing.');
        }

        return $decoded;
    }
}
