<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * Production with email not set up (owner, 2026-11-22; ops.md §7): **not sent, Super Admins warned**. It takes the email
 * and does nothing with it - never written to the log or anywhere, no address, no body - and refuses it as a setup
 * failure, which is what a bad Brevo key is (FailureKind::NotSetUp): the message's `ops.sent_messages` row is FAILED,
 * and the callers answer as they do to any provider's failure (amendment 93's words, a queued job failed at once). The
 * refusal names only the settings.
 */
final readonly class NotSetUpEmailSender implements EmailSender
{
    /** @param  list<string>  $settings  the settings that are wrong, by name */
    public function __construct(
        private array $settings,
    ) {}

    public function send(OutgoingEmail $email): ?string
    {
        throw new ProviderFailed('Email is not set up ('.implode(', ', $this->settings).'): nothing is sent.', FailureKind::NotSetUp);
    }
}
