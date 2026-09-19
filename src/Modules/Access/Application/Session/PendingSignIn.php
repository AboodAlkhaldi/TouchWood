<?php

declare(strict_types=1);

namespace Modules\Access\Application\Session;

use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Public\Enums\StaffStatus;

/**
 * Someone who gave the right password in this browser and has not entered the code yet.
 * $needsPhone: a Super Admin whose phone was reset first enters a new number (amendment 14).
 */
final readonly class PendingSignIn
{
    public function __construct(
        public string $staffId,
        public int $sessionVersion,
        public bool $needsPhone,
    ) {}

    /**
     * Whether the account is still as it was when the password was given: active, the same password
     * (a reset or change raises the session version) and, for a new number, still a Super Admin with
     * no phone (review of step 3b).
     */
    public function stillFor(?StaffUser $staff): bool
    {
        return $staff !== null
            && $staff->id() === $this->staffId
            && $staff->status() === StaffStatus::Active
            && $staff->sessionVersion() === $this->sessionVersion
            && (! $this->needsPhone || ($staff->isSuperAdmin() && $staff->phone() === null));
    }
}
