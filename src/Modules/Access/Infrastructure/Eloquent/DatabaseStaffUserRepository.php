<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\CountryCode;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Public\Enums\StaffStatus;
use stdClass;

final readonly class DatabaseStaffUserRepository implements StaffUserRepository
{
    private const string TABLE = 'access.staff_users';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function byId(string $id): ?StaffUser
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($id))->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toStaff($row) : null;
    }

    public function find(string $id): ?StaffUser
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($id))->first();

        return $row instanceof stdClass ? $this->toStaff($row) : null;
    }

    public function byEmail(EmailAddress $email): ?StaffUser
    {
        $row = $this->db->table(self::TABLE)->whereRaw('lower(email) = lower(?)', [$email->value])->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toStaff($row) : null;
    }

    public function emailInUse(EmailAddress $email, ?string $exceptStaffId = null): bool
    {
        return $this->db->table(self::TABLE)
            ->whereRaw('lower(email) = lower(?)', [$email->value])
            ->when($exceptStaffId !== null, fn ($query) => $query->where('id', '<>', $exceptStaffId))
            ->exists();
    }

    public function phoneInUse(PhoneNumber $phone, ?string $exceptStaffId = null): bool
    {
        return $this->db->table(self::TABLE)
            ->where('phone', $phone->value)
            ->when($exceptStaffId !== null, fn ($query) => $query->where('id', '<>', $exceptStaffId))
            ->exists();
    }

    public function activeSuperAdminIds(): array
    {
        /** @var list<string> */
        return $this->db->table(self::TABLE)
            ->where('is_super_admin', true)
            ->where('status', StaffStatus::Active->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();
    }

    public function names(array $ids): array
    {
        $found = [];

        foreach ($this->db->table(self::TABLE)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $row) {
            $found[(string) $row->id] = trim($row->first_name.' '.$row->last_name);
        }

        $names = [];

        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $names[$id] = $found[$id];
            }
        }

        return $names;
    }

    public function add(StaffUser $staff): void
    {
        $now = CarbonImmutable::now();

        $this->write(fn () => $this->db->table(self::TABLE)->insert([
            'id' => $staff->id(),
            ...$this->attributes($staff),
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public function update(StaffUser $staff): void
    {
        $this->write(fn () => $this->db->table(self::TABLE)->where('id', $staff->id())->update([
            ...$this->attributes($staff),
            'updated_at' => CarbonImmutable::now(),
        ]));
    }

    /**
     * The code checks both first (emailInUse, phoneInUse); the unique indexes only catch two
     * changes at the same moment.
     */
    private function write(callable $write): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException $e) {
            throw match (true) {
                str_contains($e->getMessage(), 'staff_users_email_unique') => new StaffEmailInUse,
                str_contains($e->getMessage(), 'staff_users_phone_unique') => new PhoneAlreadyInUse,
                default => $e,
            };
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StaffUser $staff): array
    {
        $profile = $staff->profile();

        return [
            'email' => $staff->email()->value,
            'password' => $staff->passwordHash(),
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'job_title' => $profile->jobTitle,
            'date_of_birth' => $profile->dateOfBirth->format('Y-m-d'),
            'country' => $profile->country->value,
            'address' => $profile->address,
            'phone' => $staff->phone()?->value,
            'phone_verified_at' => $staff->phoneVerifiedAt(),
            'avatar_media_id' => $staff->avatarMediaId(),
            'locale' => $staff->language()->value,
            'status' => $staff->status()->value,
            'is_super_admin' => $staff->isSuperAdmin(),
        ];
    }

    private function toStaff(stdClass $row): StaffUser
    {
        return StaffUser::reconstitute(
            (string) $row->id,
            EmailAddress::of((string) $row->email),
            $row->password === null ? null : (string) $row->password,
            StaffProfile::reconstitute(
                (string) $row->first_name,
                (string) $row->last_name,
                (string) $row->job_title,
                CarbonImmutable::parse((string) $row->date_of_birth)->startOfDay(),
                CountryCode::of((string) $row->country),
                $row->address === null ? null : (string) $row->address,
            ),
            $row->phone === null ? null : PhoneNumber::of((string) $row->phone),
            $row->phone_verified_at === null ? null : CarbonImmutable::parse((string) $row->phone_verified_at),
            $row->avatar_media_id === null ? null : (string) $row->avatar_media_id,
            Language::from((string) $row->locale),
            StaffStatus::from((string) $row->status),
            (bool) $row->is_super_admin,
        );
    }
}
