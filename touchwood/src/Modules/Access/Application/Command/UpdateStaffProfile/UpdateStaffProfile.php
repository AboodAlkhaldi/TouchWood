<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateStaffProfile;

/**
 * An admin edits a staff member's profile: every field is given, as the form shows them. A new
 * phone is verified by the staff member at their next sign-in (spec §1.4). The email changes
 * through ChangeStaffEmail; the communication language only in the person's own settings.
 */
final readonly class UpdateStaffProfile
{
    /**
     * @param  string  $dateOfBirth  YYYY-MM-DD
     * @param  string|null  $avatarMediaId  null for no avatar
     */
    public function __construct(
        public string $staffId,
        public string $firstName,
        public string $lastName,
        public string $jobTitle,
        public string $dateOfBirth,
        public string $country,
        public ?string $address,
        public string $phone,
        public ?string $avatarMediaId,
    ) {}
}
