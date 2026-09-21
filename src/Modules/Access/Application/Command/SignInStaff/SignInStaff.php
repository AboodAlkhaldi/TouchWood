<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignInStaff;

/**
 * The first step of signing in to the admin panel (spec §1.8, §4.4): the password.
 *
 * @param  string|null  $trustToken  this browser's trust cookie, if it has one
 */
final readonly class SignInStaff
{
    public function __construct(
        public string $email,
        public string $password,
        public string $ip,
        public ?string $trustToken = null,
    ) {}
}
