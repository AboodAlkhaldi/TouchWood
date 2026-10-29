<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use LogicException;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;

/** An EmailSender for step 7's tests: keeps every email, or refuses each one while `$fails` is set. */
final class CapturedEmails implements EmailSender
{
    private static ?self $installed = null;

    /** @var list<OutgoingEmail> */
    public array $sent = [];

    public bool $fails = false;

    public static function install(): self
    {
        $emails = new self;
        app()->instance(EmailSender::class, $emails);
        self::$installed = $emails;

        return $emails;
    }

    /** The one install() bound. */
    public static function installed(): self
    {
        return self::$installed ?? throw new LogicException('CapturedEmails::install() was not called.');
    }

    /** The last email kept. */
    public function last(): OutgoingEmail
    {
        return $this->sent[count($this->sent) - 1] ?? throw new LogicException('No email was sent.');
    }

    /** The email kept for that address. */
    public function for(string $to): OutgoingEmail
    {
        foreach ($this->sent as $email) {
            if ($email->toEmail === $to) {
                return $email;
            }
        }

        throw new LogicException("No email to {$to}.");
    }

    public function send(OutgoingEmail $email): string
    {
        if ($this->fails) {
            throw new ProviderFailed('Brevo: the API could not be reached.');
        }

        $this->sent[] = $email;

        return 'message-'.count($this->sent);
    }

    /** @return list<string> */
    public function to(): array
    {
        return array_map(static fn (OutgoingEmail $email): string => $email->toEmail, $this->sent);
    }
}
