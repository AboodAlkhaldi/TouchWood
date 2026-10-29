<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/**
 * One email as an adapter sends it (ops.md §2.2): who it is from - the store's name, or TouchWood's
 * own - who it is to, the subject, and the body as HTML and as plain text.
 */
final readonly class OutgoingEmail
{
    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public string $toEmail,
        public ?string $toName,
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
