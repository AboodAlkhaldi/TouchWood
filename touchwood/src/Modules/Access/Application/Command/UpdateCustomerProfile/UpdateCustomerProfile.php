<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateCustomerProfile;

/**
 * What a customer may change about themselves (spec §3.1): their name and the language every email
 * and SMS reaches them in. The email never changes, and the phone goes through a code (§1.3).
 */
final readonly class UpdateCustomerProfile
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $locale,
    ) {}
}
