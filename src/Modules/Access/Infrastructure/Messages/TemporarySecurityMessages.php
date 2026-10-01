<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Messages;

use DateTimeImmutable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Translation\Translator;
use Modules\Access\Application\Customer\CustomerLinks;
use Modules\Access\Application\Messages\SmsGateway;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
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
        private CustomerLinks $links,
    ) {}

    public function emailVerification(CustomerDto $customer, string $link): void
    {
        $this->mailer->to($customer->email)->send(new SecurityMail('email_verification', ['name' => $customer->firstName, 'link' => $link], $customer->locale));
    }

    public function customerDeletionScheduled(CustomerDto $customer, DateTimeImmutable $on): void
    {
        $this->mailer->to($customer->email)->send(new SecurityMail(
            'customer_deletion_scheduled',
            ['name' => $customer->firstName, 'date' => $on->format('Y-m-d')],
            $customer->locale,
        ));
    }

    public function staffInvitation(StaffDto $staff, string $link): void
    {
        $this->mailer->to($staff->email)->send(new SecurityMail('staff_invitation', ['name' => $staff->firstName, 'link' => $link], $staff->locale));
    }

    public function passwordReset(string $email, string $locale, string $link): void
    {
        $this->mailer->to($email)->send(new SecurityMail('password_reset', ['link' => $link], $locale));
    }

    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void
    {
        $this->mailer->to($newEmail)->send(new SecurityMail('staff_email_change', ['name' => $staff->firstName, 'link' => $link], $staff->locale));
    }

    public function phoneCode(string $phone, string $locale, string $code): void
    {
        $this->sms->send($phone, (string) $this->translator->get('access::messages.phone_code', ['code' => $code], $locale));
    }

    public function staffSignInCode(string $phone, string $locale, string $code): void
    {
        $this->sms->send($phone, (string) $this->translator->get('access::messages.sign_in_code', ['code' => $code], $locale));
    }

    public function companyApproved(CustomerDto $customer, ?string $note): void
    {
        // The note is a line of its own, and only when staff wrote one: an empty "A note from our
        // team:" would read as something missing.
        $note = $note === null || trim($note) === '' ? [] : [
            (string) $this->translator->get('access::messages.company_approved.note', ['note' => $note], $customer->locale),
        ];

        $this->mailer->to($customer->email)->send(new SecurityMail(
            'company_approved',
            ['name' => $customer->firstName, 'link' => $this->links->shopFrontDoor()],
            $customer->locale,
            $note,
        ));
    }

    public function companyRejected(CustomerDto $customer, string $reason): void
    {
        $this->mailer->to($customer->email)->send(new SecurityMail(
            'company_rejected',
            ['name' => $customer->firstName, 'reason' => $reason, 'link' => $this->links->shopFrontDoor()],
            $customer->locale,
        ));
    }

    public function companySuspended(CustomerDto $customer, string $reason): void
    {
        $this->mailer->to($customer->email)->send(new SecurityMail(
            'company_suspended',
            ['name' => $customer->firstName, 'reason' => $reason, 'link' => $this->links->shopFrontDoor()],
            $customer->locale,
        ));
    }
}
