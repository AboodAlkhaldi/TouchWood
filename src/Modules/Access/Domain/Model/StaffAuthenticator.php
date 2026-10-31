<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;

/**
 * A staff member's authenticator app (amendment 91): the secret in use once a setup is confirmed,
 * and a setup waiting for its first code. The secrets are plain here, in memory only; they are kept
 * encrypted with the app's key.
 */
final readonly class StaffAuthenticator
{
    public function __construct(
        public string $staffId,
        /** The app in use; null until a setup is confirmed. */
        public ?string $secret,
        public ?DateTimeImmutable $confirmedAt,
        /** The last 30-second step a code was taken for: a code is never taken twice. */
        public ?int $lastUsedStep,
        /** False when the person chose SMS while both methods are ticked; the app is kept. */
        public bool $inUse,
        /** Wrong app or recovery codes in the sign-in under way. */
        public int $attempts,
        public ?string $pendingSecret,
        public ?DateTimeImmutable $pendingAt,
        public int $pendingAttempts,
        /** Sign-ins whose wrong codes ran out, since $roundsSince (amendment 91, owner 2026-10-31). */
        public int $usedUpRounds = 0,
        public ?DateTimeImmutable $roundsSince = null,
        /** The app step is locked until the password is reset. */
        public ?DateTimeImmutable $lockedAt = null,
    ) {}

    public function isLocked(): bool
    {
        return $this->lockedAt !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->secret !== null;
    }

    /**
     * A setup still waiting for its code: opened by the password within $minutes.
     */
    public function hasPendingSince(DateTimeImmutable $now, int $minutes): bool
    {
        return $this->pendingSecret !== null && $this->pendingAt !== null
            && $now->getTimestamp() - $this->pendingAt->getTimestamp() <= $minutes * 60;
    }
}
