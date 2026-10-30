<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/**
 * One email as an adapter sends it (ops.md §2.2): who it is from - the store's name, or TouchWood's
 * own - who it is to, the subject, the body as HTML and as plain text, and any files attached (the daily
 * report's PDF, §1.11).
 *
 * `headers` are the email's own extra headers - a marketing email's RFC 8058 one-click unsubscribe
 * (`List-Unsubscribe`, `List-Unsubscribe-Post`). An adapter sends what its provider documents it can.
 */
final readonly class OutgoingEmail
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public string $toEmail,
        public ?string $toName,
        public string $subject,
        public string $html,
        public string $text,
        public array $headers = [],
        /** @var list<EmailAttachment> */
        public array $attachments = [],
    ) {}
}
