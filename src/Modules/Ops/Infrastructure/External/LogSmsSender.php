<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Illuminate\Contracts\Translation\Translator;
use Modules\Ops\Application\Messages\CodePurpose;
use Modules\Ops\Application\Messages\CodeSender;
use Modules\Ops\Application\Messages\WhatsAppAlert;
use Psr\Log\LoggerInterface;

/**
 * For development and tests (ops.md §2.2, §9.8 point 1): the code, in the words it had as an SMS
 * (`ops::security.phone_code` or `sign_in_code`, by purpose), goes to the application log. Never the driver of a live
 * system - the binding refuses it in production.
 */
final readonly class LogSmsSender implements CodeSender
{
    public function __construct(
        private LoggerInterface $log,
        private Translator $translator,
    ) {}

    public function sendCode(#[\SensitiveParameter] string $phone, string $locale, CodePurpose $purpose, #[\SensitiveParameter] string $code): ?string
    {
        $key = $purpose === CodePurpose::SignIn ? 'sign_in_code' : 'phone_code';

        $this->log->info('SMS (log driver)', ['to' => $phone, 'message' => (string) $this->translator->get("ops::security.{$key}", ['code' => $code], $locale)]);

        return null;
    }

    public function sendAlert(#[\SensitiveParameter] string $phone, string $locale, WhatsAppAlert $alert, #[\SensitiveParameter] string $text): ?string
    {
        $this->log->info('SMS (log driver)', ['to' => $phone, 'message' => $text]);

        return null;
    }
}
