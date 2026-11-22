<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Domain\Model\StaffAuthenticator;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use stdClass;

/**
 * `access.staff_authenticators` and `access.staff_recovery_codes` (amendment 91): the secrets
 * encrypted with the app's key (APP_KEY), as Sync keeps a store's provider key; the recovery codes as
 * keyed hashes only.
 */
final readonly class DatabaseStaffAuthenticatorRepository implements StaffAuthenticatorRepository
{
    private const string APPS = 'access.staff_authenticators';

    private const string RECOVERY_CODES = 'access.staff_recovery_codes';

    public function __construct(
        private ConnectionInterface $db,
        private Encrypter $encrypter,
    ) {}

    public function forStaff(string $staffId, bool $lock = false): ?StaffAuthenticator
    {
        $query = $this->db->table(self::APPS)->where('staff_user_id', $staffId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? new StaffAuthenticator(
            (string) $row->staff_user_id,
            $row->secret === null ? null : $this->encrypter->decryptString((string) $row->secret),
            $row->confirmed_at === null ? null : CarbonImmutable::parse((string) $row->confirmed_at),
            $row->last_used_step === null ? null : (int) $row->last_used_step,
            (bool) $row->in_use,
            (int) $row->attempts,
            $row->pending_secret === null ? null : $this->encrypter->decryptString((string) $row->pending_secret),
            $row->pending_at === null ? null : CarbonImmutable::parse((string) $row->pending_at),
            (int) $row->pending_attempts,
            (int) $row->used_up_rounds,
            $row->rounds_since === null ? null : CarbonImmutable::parse((string) $row->rounds_since),
            $row->locked_at === null ? null : CarbonImmutable::parse((string) $row->locked_at),
        ) : null;
    }

    public function hasConfirmedApp(string $staffId, bool $lock = false): bool
    {
        $query = $this->db->table(self::APPS)->where('staff_user_id', $staffId)->whereNotNull('confirmed_at');

        return $lock ? $query->lockForUpdate()->value('staff_user_id') !== null : $query->exists();
    }

    public function usesApp(string $staffId): bool
    {
        return $this->db->table(self::APPS)->where('staff_user_id', $staffId)->whereNotNull('confirmed_at')->where('in_use', true)->exists();
    }

    public function recordRounds(string $staffId, int $rounds, DateTimeImmutable $since, ?DateTimeImmutable $lockedAt): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->update([
            'used_up_rounds' => $rounds, 'rounds_since' => $since, 'locked_at' => $lockedAt, 'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function unlock(string $staffId): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->update([
            'used_up_rounds' => 0, 'rounds_since' => null, 'locked_at' => null,
        ]);
    }

    public function startSetup(string $staffId, string $secret, DateTimeImmutable $at): void
    {
        $this->db->table(self::APPS)->upsert([[
            'staff_user_id' => $staffId,
            'pending_secret' => $this->encrypter->encryptString($secret),
            'pending_at' => $at,
            'pending_attempts' => 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]], ['staff_user_id'], ['pending_secret', 'pending_at', 'pending_attempts', 'updated_at']);
    }

    public function countSetupAttempt(string $staffId): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->increment('pending_attempts');
    }

    public function dropSetup(string $staffId): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->whereNull('secret')->delete();
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->update([
            'pending_secret' => null, 'pending_at' => null, 'pending_attempts' => 0, 'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function confirmSetup(string $staffId, int $step, DateTimeImmutable $at): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->whereNotNull('pending_secret')->update([
            'secret' => $this->db->raw('pending_secret'),
            'confirmed_at' => $at,
            'last_used_step' => $step,
            // A first app is the one used; a new app in place of an old one keeps the person's choice.
            // (Postgres reads every column here as it was before the update.)
            'in_use' => $this->db->raw('CASE WHEN secret IS NULL THEN true ELSE in_use END'),
            'attempts' => 0,
            'pending_secret' => null,
            'pending_at' => null,
            'pending_attempts' => 0,
            'updated_at' => $at,
        ]);
    }

    public function useStep(string $staffId, int $step): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->update([
            'last_used_step' => $step, 'attempts' => 0, 'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function countAttempt(string $staffId): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->increment('attempts');
    }

    public function resetAttempts(string $staffId): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->update(['attempts' => 0]);
    }

    public function setInUse(string $staffId, bool $inUse): void
    {
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->update(['in_use' => $inUse, 'updated_at' => CarbonImmutable::now()]);
    }

    public function remove(string $staffId): void
    {
        $this->db->table(self::RECOVERY_CODES)->where('staff_user_id', $staffId)->delete();
        $this->db->table(self::APPS)->where('staff_user_id', $staffId)->delete();
    }

    public function replaceRecoveryCodes(string $staffId, array $hashes, DateTimeImmutable $at): void
    {
        $this->db->table(self::RECOVERY_CODES)->where('staff_user_id', $staffId)->delete();
        $this->db->table(self::RECOVERY_CODES)->insert(array_map(
            static fn (string $hash): array => ['staff_user_id' => $staffId, 'code_hash' => $hash, 'used_at' => null, 'created_at' => $at],
            $hashes,
        ));
    }

    public function useRecoveryCode(string $staffId, string $hash, DateTimeImmutable $at): bool
    {
        return $this->db->table(self::RECOVERY_CODES)
            ->where('staff_user_id', $staffId)
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->update(['used_at' => $at]) === 1;
    }

    public function recoveryCodesLeft(string $staffId): int
    {
        return $this->db->table(self::RECOVERY_CODES)->where('staff_user_id', $staffId)->whereNull('used_at')->count();
    }
}
