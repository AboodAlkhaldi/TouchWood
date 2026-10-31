<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use DateTimeImmutable;
use Modules\Access\Domain\Model\StaffAuthenticator;

/**
 * Staff authenticator apps and their recovery codes (amendment 91). Secrets go in and come out
 * plain; the store keeps them encrypted.
 */
interface StaffAuthenticatorRepository
{
    /**
     * @param  bool  $lock  lock the row for the rest of the transaction
     */
    public function forStaff(string $staffId, bool $lock = false): ?StaffAuthenticator;

    /**
     * Whether an app is set up, read without decrypting anything: for the yes-or-no checks.
     *
     * @param  bool  $lock  lock the row for the rest of the transaction
     */
    public function hasConfirmedApp(string $staffId, bool $lock = false): bool;

    /**
     * Whether an app is set up and not set aside for SMS (the person's choice while both are ticked),
     * read without decrypting anything.
     */
    public function usesApp(string $staffId): bool;

    /**
     * The used-up rounds and, from the third in 24 hours, the lock (amendment 91, owner 2026-10-31).
     */
    public function recordRounds(string $staffId, int $rounds, DateTimeImmutable $since, ?DateTimeImmutable $lockedAt): void;

    /**
     * The count and the lock gone: the password was reset.
     */
    public function unlock(string $staffId): void;

    /**
     * A setup waiting for its first code, replacing any other waiting one; the app in use, if any,
     * stays until the new one's code is right.
     */
    public function startSetup(string $staffId, string $secret, DateTimeImmutable $at): void;

    public function countSetupAttempt(string $staffId): void;

    /**
     * The waiting setup is dropped; a row holding nothing else goes with it.
     */
    public function dropSetup(string $staffId): void;

    /**
     * The waiting secret becomes the app in use, from this step on.
     */
    public function confirmSetup(string $staffId, int $step, DateTimeImmutable $at): void;

    /**
     * A code accepted for this step: no code of this step or an earlier one is taken again, and
     * the wrong tries start again.
     */
    public function useStep(string $staffId, int $step): void;

    public function countAttempt(string $staffId): void;

    public function resetAttempts(string $staffId): void;

    public function setInUse(string $staffId, bool $inUse): void;

    /**
     * The app, any waiting setup and every recovery code, gone.
     */
    public function remove(string $staffId): void;

    /**
     * @param  list<string>  $hashes  the new codes' keyed hashes; every old code dies
     */
    public function replaceRecoveryCodes(string $staffId, array $hashes, DateTimeImmutable $at): void;

    /**
     * Uses the code if it is one of theirs and unused.
     */
    public function useRecoveryCode(string $staffId, string $hash, DateTimeImmutable $at): bool;

    public function recoveryCodesLeft(string $staffId): int;
}
