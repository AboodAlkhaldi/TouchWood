<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SendStaffSignInCodeToNewPhone;

/**
 * A Super Admin whose phone was reset, after the right password, enters a new number; the sign-in
 * code goes there and verifies it (amendment 14).
 */
final readonly class SendStaffSignInCodeToNewPhone
{
    public function __construct(
        public string $phone,
    ) {}
}
