<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\StaffDto;

/**
 * The security messages Access sends (spec §2.3): Access binds a temporary implementation now, and
 * Ops binds its own when it is built — one binding changes (owner's decision, 2026-09-18).
 *
 * Codes and links travel only through this interface: never inside events or queued job payloads,
 * which are stored in the database. Each message is in its recipient's communication language.
 * The customer messages arrive with customer accounts (step 4). A sign-in code is a phone code:
 * after a reset, a Super Admin receives theirs on a new number (amendment 14).
 */
interface SecurityMessages
{
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
     * A code sent to a phone: to verify it, or to sign in.
     *
     * @param  string  $locale  "ar" or "en"
     */
    public function phoneCode(string $phone, string $locale, string $code): void;
}
