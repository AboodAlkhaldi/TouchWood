<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/**
 * Why checkout will not take this account at all (sales.md §1.2 step 1, §4.2 B12) — the cart names the
 * first that applies, so the page gives one note with one way out. An off store has no cart, so it is
 * never one of these.
 */
enum CartBlock: string
{
    /** A guest: sign in (or make an account) first. */
    case SignIn = 'SIGN_IN';
    /** Blocked, its deletion asked for, or emptied: verifying would not help. */
    case AccountNotActive = 'ACCOUNT_NOT_ACTIVE';
    case EmailUnverified = 'EMAIL_UNVERIFIED';
    /**
     * The saved phone's country is not on the SMS countries list (access.md amendment 90; the owner,
     * 2026-10-30): verifying would not help — another number would.
     */
    case PhoneCountryNotServed = 'PHONE_COUNTRY_NOT_SERVED';
    case PhoneUnverified = 'PHONE_UNVERIFIED';
    /** A company account not approved in this store (B2B). */
    case CompanyNotApproved = 'COMPANY_NOT_APPROVED';
}
