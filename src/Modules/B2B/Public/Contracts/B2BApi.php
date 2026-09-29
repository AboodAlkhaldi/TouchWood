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
 * An account with no company — an individual, or a company account whose first application is
 * still a draft — has none of these: null, null and false.
 */
interface B2BApi
{
    /** The company behind an account, for Sales and the admin screens. */
    public function company(string $customerId): ?CompanyDto;

    /** For the shop's banner (§4.3) and the staff screens; not Pricing, which follows the account type. */
    public function status(string $customerId): ?CompanyStatus;

    /** Sales's half of "may place an order" (handoff §7.4): true only while the company is approved. */
    public function isApproved(string $customerId): bool;

    /**
     * The account a company of this store transfers to — **null while bank transfer is temporarily
     * off**, until the store has filled in all three settings (amendment 13(c)). Checkout then
     * offers paying through staff alone, and the server refuses a transfer.
     *
     * @param  string  $storeId  a store's ULID; anything else is a caller's bug and throws
     */
    public function bankAccount(string $storeId): ?BankAccountDto;
}
