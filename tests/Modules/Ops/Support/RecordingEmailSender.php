<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * Keeps every email Ops would send instead of sending it, so a test can read its subject, its lines
 * and its link. `failing()` makes the provider refuse instead.
 */
final class RecordingEmailSender implements EmailSender
{
    /** @var list<OutgoingEmail> */
    public array $sent = [];

    private bool $fails = false;

    public static function install(): self
    {
        $sender = new self;
        app()->instance(EmailSender::class, $sender);

        return $sender;
    }

    public function failing(): self
    {
        $this->fails = true;

        return $this;
    }

    public function send(OutgoingEmail $email): string
    {
        if ($this->fails) {
            throw new ProviderFailed('Brevo refused the email: HTTP 401 (unauthorized).');
        }

        $this->sent[] = $email;

        return 'email-'.count($this->sent);
    }

    public function last(): OutgoingEmail
    {
        $last = end($this->sent);

        expect($last)->toBeInstanceOf(OutgoingEmail::class, 'no email was sent');
        assert($last instanceof OutgoingEmail);

        return $last;
    }
}
