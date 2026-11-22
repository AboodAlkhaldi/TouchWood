<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Domain\Model\PhoneCode;
use Modules\Access\Domain\Model\StaffEmailChange;
use Modules\Access\Domain\Model\StaffInvitation;
use Modules\Access\Domain\Model\StaffPasswordReset;
use Modules\Access\Domain\Model\TrustedBrowser;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use stdClass;

final readonly class DatabaseStaffTokenRepository implements StaffTokenRepository
{
    private const string INVITATIONS = 'access.staff_invitations';

    private const string EMAIL_CHANGES = 'access.staff_email_changes';

    private const string PHONE_CODES = 'access.staff_phone_codes';

    private const string SIGN_IN_CODES = 'access.staff_sign_in_codes';

    private const string PASSWORD_RESETS = 'access.staff_password_resets';

    private const string TRUSTED_BROWSERS = 'access.staff_trusted_browsers';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function putInvitation(string $staffId, string $tokenHash, DateTimeImmutable $expiresAt, ?string $invitedBy): void
    {
        $this->db->table(self::INVITATIONS)->upsert([[
            'staff_user_id' => $staffId,
            'token_hash' => $tokenHash,
            'pending_password' => null,
            'expires_at' => $expiresAt,
            'invited_by' => $invitedBy,
            'created_at' => CarbonImmutable::now(),
        ]], ['staff_user_id'], ['token_hash', 'pending_password', 'expires_at', 'invited_by', 'created_at']);
    }

    public function invitationByToken(string $tokenHash): ?StaffInvitation
    {
        $row = $this->db->table(self::INVITATIONS)->where('token_hash', $tokenHash)->lockForUpdate()->first();

        return $row instanceof stdClass ? new StaffInvitation(
            (string) $row->staff_user_id,
            CarbonImmutable::parse((string) $row->expires_at),
            $row->pending_password === null ? null : (string) $row->pending_password,
        ) : null;
    }

    public function setPendingPassword(string $staffId, string $passwordHash): void
    {
        $this->db->table(self::INVITATIONS)->where('staff_user_id', $staffId)->update(['pending_password' => $passwordHash]);
    }

    public function deleteInvitation(string $staffId): void
    {
        $this->db->table(self::INVITATIONS)->where('staff_user_id', $staffId)->delete();
    }

    public function putEmailChange(string $staffId, EmailAddress $newEmail, string $tokenHash, DateTimeImmutable $expiresAt, ?string $requestedBy): void
    {
        $this->db->table(self::EMAIL_CHANGES)->upsert([[
            'staff_user_id' => $staffId,
            'new_email' => $newEmail->value,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'requested_by' => $requestedBy,
            'created_at' => CarbonImmutable::now(),
        ]], ['staff_user_id'], ['new_email', 'token_hash', 'expires_at', 'requested_by', 'created_at']);
    }

    public function emailChangeByToken(string $tokenHash): ?StaffEmailChange
    {
        $row = $this->db->table(self::EMAIL_CHANGES)->where('token_hash', $tokenHash)->lockForUpdate()->first();

        return $row instanceof stdClass ? new StaffEmailChange(
            (string) $row->staff_user_id,
            EmailAddress::of((string) $row->new_email),
            CarbonImmutable::parse((string) $row->expires_at),
            $row->requested_by === null ? null : (string) $row->requested_by,
        ) : null;
    }

    public function pendingEmailChange(string $staffId): ?StaffEmailChange
    {
        // No lock: this one is read to be shown on a screen, never to be acted on. Only the link
        // sent to the new address finishes the change, and that path locks by token above.
        $row = $this->db->table(self::EMAIL_CHANGES)->where('staff_user_id', $staffId)->first();

        return $row instanceof stdClass ? new StaffEmailChange(
            (string) $row->staff_user_id,
            EmailAddress::of((string) $row->new_email),
            CarbonImmutable::parse((string) $row->expires_at),
            $row->requested_by === null ? null : (string) $row->requested_by,
        ) : null;
    }

    public function deleteEmailChange(string $staffId): void
    {
        $this->db->table(self::EMAIL_CHANGES)->where('staff_user_id', $staffId)->delete();
    }

    public function putPhoneCode(PhoneCode $code): void
    {
        $row = [
            'staff_user_id' => $code->staffId,
            'phone' => $code->phone->value,
            'code_hash' => $code->codeHash,
            'attempts' => $code->attempts,
            'expires_at' => $code->expiresAt,
            'sent_at' => $code->sentAt,
        ];

        if ($code->purpose === PhoneCodePurpose::SignIn) {
            $this->db->table(self::SIGN_IN_CODES)->upsert([$row], ['staff_user_id'], ['phone', 'code_hash', 'attempts', 'expires_at', 'sent_at']);

            return;
        }

        $this->db->table(self::PHONE_CODES)->upsert([[...$row, 'purpose' => $code->purpose->value]], ['staff_user_id'], ['purpose', 'phone', 'code_hash', 'attempts', 'expires_at', 'sent_at']);
    }

    public function phoneCode(string $staffId, PhoneCodePurpose $purpose): ?PhoneCode
    {
        $row = $this->db->table($this->codesTable($purpose))->where('staff_user_id', $staffId)->lockForUpdate()->first();

        return $row instanceof stdClass ? new PhoneCode(
            (string) $row->staff_user_id,
            $purpose === PhoneCodePurpose::SignIn ? PhoneCodePurpose::SignIn : PhoneCodePurpose::from((string) $row->purpose),
            PhoneNumber::of((string) $row->phone),
            (string) $row->code_hash,
            (int) $row->attempts,
            CarbonImmutable::parse((string) $row->expires_at),
            CarbonImmutable::parse((string) $row->sent_at),
        ) : null;
    }

    public function countFailedAttempt(string $staffId, PhoneCodePurpose $purpose): void
    {
        $this->db->table($this->codesTable($purpose))->where('staff_user_id', $staffId)->increment('attempts');
    }

    public function deletePhoneCode(string $staffId, ?PhoneCodePurpose $purpose = null): void
    {
        $tables = $purpose === null ? [self::PHONE_CODES, self::SIGN_IN_CODES] : [$this->codesTable($purpose)];

        foreach ($tables as $table) {
            $this->db->table($table)->where('staff_user_id', $staffId)->delete();
        }
    }

    public function putPasswordReset(string $staffId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $this->db->table(self::PASSWORD_RESETS)->upsert([[
            'staff_user_id' => $staffId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => CarbonImmutable::now(),
        ]], ['staff_user_id'], ['token_hash', 'expires_at', 'created_at']);
    }

    public function passwordResetByToken(string $tokenHash): ?StaffPasswordReset
    {
        $row = $this->db->table(self::PASSWORD_RESETS)->where('token_hash', $tokenHash)->lockForUpdate()->first();

        return $row instanceof stdClass
            ? new StaffPasswordReset((string) $row->staff_user_id, CarbonImmutable::parse((string) $row->expires_at))
            : null;
    }

    public function deletePasswordReset(string $staffId): void
    {
        $this->db->table(self::PASSWORD_RESETS)->where('staff_user_id', $staffId)->delete();
    }

    public function addTrustedBrowser(TrustedBrowser $browser, string $tokenHash): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TRUSTED_BROWSERS)->insert([
            'id' => $browser->id,
            'staff_user_id' => $browser->staffId,
            'token_hash' => $tokenHash,
            'expires_at' => $browser->expiresAt,
            'created_at' => $now,
            'last_used_at' => $now,
        ]);
    }

    public function trustedBrowser(string $tokenHash): ?TrustedBrowser
    {
        $row = $this->db->table(self::TRUSTED_BROWSERS)->where('token_hash', $tokenHash)->first();

        return $row instanceof stdClass
            ? new TrustedBrowser((string) $row->id, (string) $row->staff_user_id, CarbonImmutable::parse((string) $row->expires_at))
            : null;
    }

    public function touchTrustedBrowser(string $id, DateTimeImmutable $usedAt): void
    {
        $this->db->table(self::TRUSTED_BROWSERS)->where('id', $id)->update(['last_used_at' => $usedAt]);
    }

    /**
     * @return list<TrustedBrowser>
     */
    public function trustedBrowsersFor(string $staffId): array
    {
        $rows = $this->db->table(self::TRUSTED_BROWSERS)
            ->where('staff_user_id', $staffId)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->orderByDesc('created_at')
            ->get();

        return array_values(array_map(static fn (object $row): TrustedBrowser => new TrustedBrowser(
            (string) $row->id,
            (string) $row->staff_user_id,
            CarbonImmutable::parse((string) $row->expires_at),
        ), $rows->all()));
    }

    public function forgetTrustedBrowser(string $id, string $staffId): void
    {
        $this->db->table(self::TRUSTED_BROWSERS)->where('id', $id)->where('staff_user_id', $staffId)->delete();
    }

    public function forgetTrustedBrowsers(string $staffId): void
    {
        $this->db->table(self::TRUSTED_BROWSERS)->where('staff_user_id', $staffId)->delete();
    }

    private function codesTable(PhoneCodePurpose $purpose): string
    {
        return $purpose === PhoneCodePurpose::SignIn ? self::SIGN_IN_CODES : self::PHONE_CODES;
    }
}
