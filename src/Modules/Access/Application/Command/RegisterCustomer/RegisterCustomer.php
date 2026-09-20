<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RegisterCustomer;

/**
 * A new customer account in the store the request is in (spec §1.2): email, password, the person's
 * name, the kind of account, the page's language, and acceptance of that store's terms.
 */
final readonly class RegisterCustomer
{
    public function __construct(
        public string $email,
        public string $password,
        public string $firstName,
        public string $lastName,
        public string $accountType,
        public string $locale,
        public bool $termsAccepted,
    ) {}
}
