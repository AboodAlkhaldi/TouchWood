<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Domain\Model\PhoneCode;
use Modules\Access\Domain\Model\StaffEmailChange;
use Modules\Access\Domain\Model\StaffInvitation;
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

    public function deleteEmailChange(string $staffId): void
    {
        $this->db->table(self::EMAIL_CHANGES)->where('staff_user_id', $staffId)->delete();
    }

    public function putPhoneCode(PhoneCode $code): void
    {
        $this->db->table(self::PHONE_CODES)->upsert([[
            'staff_user_id' => $code->staffId,
            'purpose' => $code->purpose->value,
            'phone' => $code->phone->value,
            'code_hash' => $code->codeHash,
            'attempts' => $code->attempts,
            'expires_at' => $code->expiresAt,
            'sent_at' => $code->sentAt,
        ]], ['staff_user_id'], ['purpose', 'phone', 'code_hash', 'attempts', 'expires_at', 'sent_at']);
    }

    public function phoneCode(string $staffId): ?PhoneCode
    {
        $row = $this->db->table(self::PHONE_CODES)->where('staff_user_id', $staffId)->lockForUpdate()->first();

        return $row instanceof stdClass ? new PhoneCode(
            (string) $row->staff_user_id,
            PhoneCodePurpose::from((string) $row->purpose),
            PhoneNumber::of((string) $row->phone),
            (string) $row->code_hash,
            (int) $row->attempts,
            CarbonImmutable::parse((string) $row->expires_at),
            CarbonImmutable::parse((string) $row->sent_at),
        ) : null;
    }

    public function countFailedAttempt(string $staffId): void
    {
        $this->db->table(self::PHONE_CODES)->where('staff_user_id', $staffId)->increment('attempts');
    }

    public function deletePhoneCode(string $staffId): void
    {
        $this->db->table(self::PHONE_CODES)->where('staff_user_id', $staffId)->delete();
    }
}
