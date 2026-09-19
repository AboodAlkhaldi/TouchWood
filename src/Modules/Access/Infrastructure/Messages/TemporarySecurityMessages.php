<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Messages;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Translation\Translator;
use Modules\Access\Application\Messages\SmsGateway;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\StaffDto;

/**
 * Access's temporary sender, until Ops binds its own (owner's decision, 2026-09-18): Laravel mail
 * (the `log` mailer until an email provider is chosen) and the SmsGateway.
 */
final readonly class TemporarySecurityMessages implements SecurityMessages
{
    public function __construct(
        private Mailer $mailer,
        private SmsGateway $sms,
        private Translator $translator,
    ) {}

    public function staffInvitation(StaffDto $staff, string $link): void
    {
        $this->mailer->to($staff->email)->send(new SecurityMail('staff_invitation', ['name' => $staff->firstName, 'link' => $link], $staff->locale));
    }

    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void
    {
        $this->mailer->to($newEmail)->send(new SecurityMail('staff_email_change', ['name' => $staff->firstName, 'link' => $link], $staff->locale));
    }

    public function phoneCode(string $phone, string $locale, string $code): void
    {
        $this->sms->send($phone, (string) $this->translator->get('access::messages.phone_code', ['code' => $code], $locale));
    }
}
