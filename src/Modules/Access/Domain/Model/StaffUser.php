<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use Modules\Access\Public\Enums\StaffStatus;

/**
 * A staff account (Access spec §1.4). Step 2 only reads it, to decide who may manage whom; the
 * invitation, sign-in and profile arrive with staff sign-in.
 */
final class StaffUser
{
    private function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $locale,
        private readonly StaffStatus $status,
        private readonly bool $superAdmin,
    ) {}

    public static function reconstitute(string $id, string $email, string $firstName, string $lastName, string $locale, StaffStatus $status, bool $superAdmin): self
    {
        return new self($id, $email, $firstName, $lastName, $locale, $status, $superAdmin);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function status(): StaffStatus
    {
        return $this->status;
    }

    /**
     * Set only by the console command (spec §1.6). A Super Admin has no role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }
}
