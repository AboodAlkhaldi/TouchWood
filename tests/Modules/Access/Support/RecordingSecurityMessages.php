<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use LogicException;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\StaffDto;

/**
 * Keeps every security message instead of sending it, so a test can read the link or code a
 * person would receive — the only place they exist in plain text.
 */
final class RecordingSecurityMessages implements SecurityMessages
{
    /** @var list<array{to: string, link: string, locale: string}> */
    public array $invitations = [];

    /** @var list<array{staff: string, to: string, link: string}> */
    public array $emailChanges = [];

    /** @var list<array{phone: string, locale: string, code: string}> */
    public array $codes = [];

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
        return self::$installed ?? throw new LogicException('RecordingSecurityMessages is not installed in this test');
    }

    public function staffInvitation(StaffDto $staff, string $link): void
    {
        $this->invitations[] = ['to' => $staff->email, 'link' => $link, 'locale' => $staff->locale];
    }

    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void
    {
        $this->emailChanges[] = ['staff' => $staff->id, 'to' => $newEmail, 'link' => $link];
    }

    public function phoneCode(string $phone, string $locale, string $code): void
    {
        $this->codes[] = ['phone' => $phone, 'locale' => $locale, 'code' => $code];
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
