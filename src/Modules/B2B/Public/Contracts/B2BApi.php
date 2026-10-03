<?php

declare(strict_types=1);

namespace Modules\B2B\Public\Contracts;

use Modules\B2B\Public\Dto\BankAccountDto;
use Modules\B2B\Public\Dto\CompanyDto;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * What other modules may ask B2B (b2b.md §2.1): ids in, DTOs out. Module to module, so no
 * permission is checked here — the calling use case checks its own.
 *
 * **Per store** (amendments 18 and 19(d)): an account may hold a company in each store it applied
 * in, and ordering in a store needs **that store's** company approved. An account with no company in
 * a store — an individual, or a company account whose first application there is still a draft —
 * has none of these there: null, null and false.
 */
interface B2BApi
{
    /** The account's company in this store, for Sales and the admin screens. */
    public function company(string $customerId, string $storeId): ?CompanyDto;

    /**
     * Every company of the account, one per store it applied in, newest first — for the admin
     * screens.
     *
     * @return list<CompanyDto>
     */
    public function companies(string $customerId): array;

    /** For the shop's banner (§4.3) and the staff screens; not Pricing, which follows the account type. */
    public function status(string $customerId, string $storeId): ?CompanyStatus;

    /**
     * Sales's half of "may place an order" (handoff §7.4) in this store: true only while this
     * store's company is approved — and never while the store is off (platform.md §1.6).
     */
    public function isApproved(string $customerId, string $storeId): bool;

    /**
     * The account a company of this store transfers to — **null while bank transfer is temporarily
     * off**, until the store has filled in all three settings (amendment 13(c)). Checkout then
     * offers paying through staff alone, and the server refuses a transfer.
     *
     * @param  string  $storeId  a store's ULID; anything else is a caller's bug and throws
     */
    public function bankAccount(string $storeId): ?BankAccountDto;
}
