<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Messages;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One security email, in its recipient's language. Sent at once, never queued: its link must not
 * be stored in a queued job's payload (spec §2.3).
 */
final class SecurityMail extends Mailable
{
    /**
     * @param  string  $message  the key under access::messages, e.g. "staff_invitation"
     * @param  array<string, string>  $values
     */
    public function __construct(
        public readonly string $message,
        public readonly array $values,
        string $language,
    ) {
        $this->locale($language);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __("access::messages.{$this->message}.subject", $this->values, $this->locale));
    }

    public function content(): Content
    {
        return new Content(view: 'access::mail.security', with: [
            'lines' => (array) __("access::messages.{$this->message}.lines", $this->values, $this->locale),
            'action' => (string) __("access::messages.{$this->message}.action", $this->values, $this->locale),
            'link' => $this->values['link'] ?? null,
            'direction' => $this->locale === 'ar' ? 'rtl' : 'ltr',
        ]);
    }
}
