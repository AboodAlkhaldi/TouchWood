<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use DateTimeImmutable;
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
     * The one message a deletion sends (spec §1.10, amendment 43): the date the account is
     * anonymized, and that signing in before then cancels it. Nothing is sent afterwards — by then
     * the address is gone.
     */
    public function customerDeletionScheduled(CustomerDto $customer, DateTimeImmutable $on): void;

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

    /*
     * B2B's decisions about a company, told to the account holder (b2b.md §2.3; amendment 48).
     * B2B's own messages, sent from here until Ops, like every other email to a customer. Each
     * carries one plain link to the shop's front door, the same for all three (owner, 2026-09-27).
     * A reinstatement sends none: it is the suspension notice disappearing.
     */

    /**
     * @param  string|null  $note  what the staff member chose to add when approving, if anything —
     *                             the screen tells them it is sent to the customer (owner, 2026-09-27)
     * @param  string|null  $storeId  the company's store, which the email names: an account may hold
     *                                a company in each store (b2b.md amendments 19(c), 20(i))
     */
    public function companyApproved(CustomerDto $customer, ?string $note, ?string $storeId = null): void;

    /**
     * @param  string  $reason  why, in the staff member's words: required (b2b.md §1.1)
     * @param  string|null  $storeId  the company's store, which the email names (amendment 19(c))
     */
    public function companyRejected(CustomerDto $customer, string $reason, ?string $storeId = null): void;

    /**
     * @param  string  $reason  why, in the staff member's words: required (b2b.md §1.1)
     * @param  string|null  $storeId  the company's store, which the email names (amendment 19(c))
     */
    public function companySuspended(CustomerDto $customer, string $reason, ?string $storeId = null): void;
}
