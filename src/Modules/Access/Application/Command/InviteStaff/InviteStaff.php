<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\InviteStaff;

use Modules\Access\Application\Command\ChangeStaffRole\ActionStores;
use Modules\Access\Application\Command\ChangeStaffRole\PersonalRole;
use Modules\Access\Public\Enums\AccessLevel;

/**
 * A new staff member with their whole profile, their role and its stores (spec §3.2, amendment
 * 15). Exactly one of $savedRoleId and $personalRole is given, as for ChangeStaffRole.
 */
final readonly class InviteStaff
{
    /**
     * @param  string  $dateOfBirth  YYYY-MM-DD
     * @param  string  $locale  the communication language; the screen defaults it to the inviting admin's
     * @param  list<string>  $storeIds  empty for all stores
     * @param  list<ActionStores>  $exceptions
     */
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $jobTitle,
        public string $dateOfBirth,
        public string $country,
        public ?string $address,
        public string $phone,
        public string $locale,
        public AccessLevel $accessLevel,
        public array $storeIds = [],
        public array $exceptions = [],
        public ?string $savedRoleId = null,
        public ?PersonalRole $personalRole = null,
    ) {}
}
