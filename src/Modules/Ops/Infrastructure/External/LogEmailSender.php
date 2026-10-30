<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Psr\Log\LoggerInterface;

/**
 * For development and tests (ops.md §2.2): the email goes to the application log, as Access's log SMS
 * driver does. Never the driver of a live system - the binding refuses it in production.
 */
final readonly class LogEmailSender implements EmailSender
{
    public function __construct(
        private LoggerInterface $log,
    ) {}

    public function send(OutgoingEmail $email): ?string
    {
        $this->log->info('Email (log driver)', [
            'from' => $email->fromEmail,
            'to' => $email->toEmail,
            'subject' => $email->subject,
            'text' => $email->text,
            ...($email->headers === [] ? [] : ['headers' => $email->headers]),
        ]);

        return null;
    }
}
