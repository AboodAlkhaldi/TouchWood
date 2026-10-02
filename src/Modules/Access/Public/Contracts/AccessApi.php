<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDisplayNameDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Dto\StaffNotificationPreferenceDto;

/**
 * What other modules may ask Access (spec §2.1). The address reads arrived with step 5.
 */
interface AccessApi
{
    public function customer(string $customerId): ?CustomerDto;

    /**
     * The person's part of handoff §7.4: active, email and phone verified, no deletion pending.
     * Sales adds the company's part for a company account, which B2B owns (spec §1.1).
     */
    public function customerMayOrder(string $customerId): bool;

    /**
     * The staff member, for the work of other modules — sending them a notification, say. **Not for
     * showing who did something**: a Super Admin's name must never reach an admin or staff member,
     * so a name shown to a reader comes from staffDisplayNames() (amendment 54).
     */
    public function staff(string $staffId): ?StaffDto;

    /**
     * How each staff member is named **to the person reading** (access.md §1.6, amendment 54) —
     * "decided by", "invited by", the actor of an entry. A Super Admin, read by anyone but another
     * Super Admin, is "System administrator", with no name and no id. Ids that are not a staff
     * member's are left out.
     *
     * @param  list<string>  $staffIds
     * @return array<string, StaffDisplayNameDto> keyed by id, lower-cased
     */
    public function staffDisplayNames(array $staffIds): array;

    /**
     * For Ops, when it sends staff notifications: every topic, with its email and panel toggles.
     *
     * @return list<StaffNotificationPreferenceDto>
     */
    public function staffNotificationPreferences(string $staffId): array;

    /**
     * One address by its id, whoever it belongs to: this read is not scoped to a customer, so a
     * caller that took the id from a request must compare `customerId` before showing or shipping
     * to it (review of step 5).
     */
    public function address(string $addressId): ?AddressDto;

    /**
     * The customer's addresses in one store, the default first, then the newest (spec §1.9):
     * checkout needs one in the store being ordered from. An address whose `isComplete` is false no
     * longer satisfies that store's format and may not be shipped to (amendment 41).
     *
     * @return list<AddressDto>
     */
    public function addresses(string $customerId, string $storeId): array;
}
