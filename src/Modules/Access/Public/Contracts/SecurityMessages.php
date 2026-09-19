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
 * The customer messages arrive with customer accounts (step 4), sign-in codes and password resets
 * with staff sign-in (step 3b).
 */
interface SecurityMessages
{
    /**
     * @param  string  $link  the invitation link (72 hours)
     */
    public function staffInvitation(StaffDto $staff, string $link): void;

    /**
     * Sent to the NEW address, which becomes the staff member's email when the link is used
     * (amendment 17).
     */
    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void;

    /**
     * A code that verifies a phone, sent to that phone.
     *
     * @param  string  $locale  "ar" or "en"
     */
    public function phoneCode(string $phone, string $locale, string $code): void;
}
