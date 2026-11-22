<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateOwnStaffProfile;

/**
 * A staff member edits their own profile and communication language (amendment 16). Every field is
 * given, as the form shows them. The phone changes through its code; the email through an admin, or
 * ChangeStaffEmail for a Super Admin.
 */
final readonly class UpdateOwnStaffProfile
{
    /**
     * @param  string  $dateOfBirth  YYYY-MM-DD
     * @param  string  $locale  the communication language: every email and code comes in it
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $jobTitle,
        public string $dateOfBirth,
        public string $country,
        public ?string $address,
        public string $locale,
        public ?string $avatarMediaId,
    ) {}
}
