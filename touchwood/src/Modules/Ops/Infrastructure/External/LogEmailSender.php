<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Modules\Ops\Application\Messages\EmailAttachment;
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
        $context = [
            'from' => $email->fromEmail,
            'to' => $email->toEmail,
            'subject' => $email->subject,
            'text' => $email->text,
            ...($email->headers === [] ? [] : ['headers' => $email->headers]),
        ];
        if ($email->attachments !== []) {
            // The files' names and sizes only: their bytes never reach the log.
            $context['attachments'] = array_map(static fn (EmailAttachment $file): string => $file->name.' ('.strlen($file->content).' bytes)', $email->attachments);
        }
        $this->log->info('Email (log driver)', $context);

        return null;
    }
}
