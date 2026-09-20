<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;

/**
 * The security messages Access sends (spec §2.3): Access binds a temporary implementation now, and
 * Ops binds its own when it is built — one binding changes (owner's decision, 2026-09-18).
 *
 * Codes and links travel only through this interface: never inside events or queued job payloads,
 * which are stored in the database. Each message is in its recipient's communication language.
 * The customer messages arrive with customer accounts (step 4).
 */
interface SecurityMessages
{
    /**
     * The link that verifies a customer's email address (24 hours, spec §1.2), in their language.
     */
    public function emailVerification(CustomerDto $customer, string $link): void;

    /**
     * @param  string  $link  the invitation link (72 hours; a Super Admin's 24)
     */
    public function staffInvitation(StaffDto $staff, string $link): void;

    /**
     * A link to choose a new password (a staff member's works 30 minutes, amendment 31).
     *
     * @param  string  $locale  "ar" or "en"
     */
    public function passwordReset(string $email, string $locale, string $link): void;

    /**
     * Sent to the NEW address, which becomes the staff member's email when the link is used
     * (amendment 17).
     */
    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void;

    /**
     * A code sent to a phone to verify it.
     *
     * @param  string  $locale  "ar" or "en"
     */
    public function phoneCode(string $phone, string $locale, string $code): void;

    /**
     * A staff sign-in code, which also warns that the password was just used: if it was not them,
     * someone else has it (owner's decision, 2026-09-19; amendment 34). Sent to $phone, not taken
     * from the account: a Super Admin whose phone was reset gets theirs on a number not yet on it.
     *
     * @param  string  $locale  "ar" or "en"
     */
    public function staffSignInCode(string $phone, string $locale, string $code): void;
}
