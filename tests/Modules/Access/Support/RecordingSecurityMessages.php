<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use DateTimeImmutable;
use LogicException;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;

/**
 * Keeps every security message instead of sending it, so a test can read the link or code a
 * person would receive — the only place they exist in plain text.
 */
final class RecordingSecurityMessages implements SecurityMessages
{
    /** @var list<array{to: string, link: string, locale: string}> */
    public array $invitations = [];

    /** @var list<array{to: string, link: string, locale: string}> */
    public array $emailVerifications = [];

    /** @var list<array{to: string, on: string, locale: string}> */
    public array $deletions = [];

    /** @var list<array{staff: string, to: string, link: string}> */
    public array $emailChanges = [];

    /** @var list<array{phone: string, locale: string, code: string, kind: 'verify'|'sign_in'}> */
    public array $codes = [];

    /** @var list<array{to: string, locale: string, link: string}> */
    public array $passwordResets = [];

    private static ?self $installed = null;

    public static function install(): self
    {
        self::$installed = new self;
        app()->instance(SecurityMessages::class, self::$installed);

        return self::$installed;
    }

    /**
     * The recorder the running test installed, which the application sends through.
     */
    public static function installed(): self
    {
        $current = spl_object_id(app(SecurityMessages::class));

        // A recorder left over from an earlier test would pass every "nothing was sent" check.
        if (self::$installed === null || spl_object_id(self::$installed) !== $current) {
            throw new LogicException('RecordingSecurityMessages is not installed in this test');
        }

        return self::$installed;
    }

    public function emailVerification(CustomerDto $customer, string $link): void
    {
        $this->emailVerifications[] = ['to' => $customer->email, 'link' => $link, 'locale' => $customer->locale];
    }

    public function customerDeletionScheduled(CustomerDto $customer, DateTimeImmutable $on): void
    {
        $this->deletions[] = ['to' => $customer->email, 'on' => $on->format('Y-m-d'), 'locale' => $customer->locale];
    }

    public function staffInvitation(StaffDto $staff, string $link): void
    {
        $this->invitations[] = ['to' => $staff->email, 'link' => $link, 'locale' => $staff->locale];
    }

    public function passwordReset(string $email, string $locale, string $link): void
    {
        $this->passwordResets[] = ['to' => $email, 'locale' => $locale, 'link' => $link];
    }

    /**
     * The token at the end of the last password reset link.
     */
    public function lastPasswordResetToken(): string
    {
        $last = end($this->passwordResets);

        return $last === false ? '' : basename($last['link']);
    }

    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void
    {
        $this->emailChanges[] = ['staff' => $staff->id, 'to' => $newEmail, 'link' => $link];
    }

    public function phoneCode(string $phone, string $locale, string $code): void
    {
        $this->codes[] = ['phone' => $phone, 'locale' => $locale, 'code' => $code, 'kind' => 'verify'];
    }

    public function staffSignInCode(string $phone, string $locale, string $code): void
    {
        $this->codes[] = ['phone' => $phone, 'locale' => $locale, 'code' => $code, 'kind' => 'sign_in'];
    }

    /**
     * The token at the end of the last invitation link.
     */
    public function lastInvitationToken(): string
    {
        $last = end($this->invitations);

        return $last === false ? '' : basename($last['link']);
    }

    public function lastEmailChangeToken(): string
    {
        $last = end($this->emailChanges);

        return $last === false ? '' : basename($last['link']);
    }

    public function lastCode(): string
    {
        $last = end($this->codes);

        return $last === false ? '' : $last['code'];
    }
}
