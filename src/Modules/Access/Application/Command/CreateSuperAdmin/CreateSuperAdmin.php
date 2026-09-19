<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CreateSuperAdmin;

/**
 * Only from the server's console (spec §1.6, amendment 18). A new account needs the whole profile;
 * an existing staff member is promoted and keeps theirs, so only the email is needed then.
 */
final readonly class CreateSuperAdmin
{
    /**
     * @param  string|null  $dateOfBirth  YYYY-MM-DD
     */
    public function __construct(
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $jobTitle = null,
        public ?string $dateOfBirth = null,
        public ?string $country = null,
        public ?string $phone = null,
        public ?string $locale = null,
        public ?string $address = null,
    ) {}
}
