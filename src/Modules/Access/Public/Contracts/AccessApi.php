<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use DateTimeImmutable;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffAlertRecipientDto;
use Modules\Access\Public\Dto\StaffDisplayNameDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Dto\StaffNotificationPreferenceDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Access (spec §2.1). The address reads arrived with step 5.
 */
interface AccessApi
{
    public function customer(string $customerId): ?CustomerDto;

    /**
     * The person's part of handoff §7.4: active, email and phone verified, no deletion pending.
     * Sales adds the company's part for a company account, which B2B owns (spec §1.1). A phone whose
     * country is not on the SMS countries list counts as unverified (amendment 90).
     */
    public function customerMayOrder(string $customerId): bool;

    /**
     * For Promotions' segments (amendment 70; promotions.md §1.5): **many customers in one read**,
     * keyed by id, an unknown id left out, at most 1000 ids a read — so a refresh of thousands never
     * asks one at a time (handoff §5.4). The caller decides what of them a person may see: Promotions
     * shows a member's **name only** to a store's staff (amendment 70).
     *
     * @param  list<string>  $customerIds
     * @return array<string, CustomerDto>
     */
    public function customers(array $customerIds): array;

    /**
     * For Promotions' segments (amendment 70): **the customers registered in the store**, of that
     * account type, not anonymized — keyset-paged by id, at most `$limit` (1 to 1000) after `$after`.
     *
     * @return list<string>
     */
    public function customerIdsInStore(StoreId $store, AccountType $accountType, ?string $after, int $limit): array;

    /**
     * For a module's own staff list searched by a person (amendment 72(b); Loyalty's points
     * holders): the customers, not anonymized, whose first or last name holds the text **in any
     * store**, or whose email or phone holds it **among the customers the acting staff member may
     * view** (amendment 44(b), in the query) — so an email typed in never names a customer the reader
     * could not see. By id, at most `$limit` (1 to 1000). The caller keeps only its own customers and
     * shows nothing more than its rules allow.
     *
     * @return list<string>
     *
     * @throws DomainError (Access's InvalidAccessAttribute) for a text of fewer than 3 or more than
     *                     100 characters, or one with control characters
     */
    public function customerIdsMatching(string $text, int $limit): array;

    /**
     * The home stores whose customers the acting staff member may view - open their page, read their
     * email and phone (amendment 44(b), G2's own rule) - for a module deciding what of a customer to
     * show (amendment 72(e)): compare with `CustomerDto::$homeStoreId`. Null for every store, now and
     * later; an empty list for none (a customer, a guest, the system without the job).
     *
     * @return list<StoreId>|null
     */
    public function customerViewStores(): ?array;

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
     * For Ops (amendment 80; ops.md §1.4): who receives a staff alert of `$topic` in `$store`, in one
     * query - the **active** staff who hold the per-store `$permission` there, as the permission
     * check would answer (roles nest: an admin role holds what it is given, a Super Admin everything;
     * an admin-only permission no staff role; a reserved one, or one that is not a role's, Super
     * Admins only), each with their switches for the topic (no row reads as its default, amendment 19) and
     * their critical-SMS switch (amendment 81). A Super Admin is never hidden from this read, which
     * no person sees. **An off store's staff are alerted all the same.**
     *
     * @return list<StaffAlertRecipientDto>
     *
     * @throws \InvalidArgumentException when no module declares `$permission`, or it is an automatic
     *                                   staff permission or a store-free one that is not reserved: an
     *                                   alert belongs to one store, and lands only in bells that show it
     */
    public function staffToAlert(StoreId $store, StaffNotificationTopic $topic, string $permission): array;

    /**
     * For Ops' staff bell (amendment 88; ops.md §1.2): the stores the staff member's bell shows - a
     * Super Admin every store; anyone else **every store their assignment covers, on or off** (owner,
     * 2026-10-30; the panel's store menu offers only the ones that are on). None for an account that
     * is not active, has no role, or is not a staff member's.
     *
     * @return list<StoreId> in the stores' own order
     */
    public function staffStores(string $staffId): array;

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

    /**
     * For Ops' newsletter box (amendment 79, as built): whether a customer account has this email,
     * ignoring case - a guest giving it is told to tick the box in their account instead (ops.md §5).
     * A yes or no only, never the account; what is not an email is no customer's.
     */
    public function isCustomerEmail(string $email): bool;

    /**
     * For Ops' daily report (amendment 83): how many accounts of each type registered in the store
     * from `$from` (included) to `$to` (excluded), anonymized ones included — they were new then.
     * Counts only; no person.
     *
     * @return array{individuals: int, companies: int}
     */
    public function customersRegistered(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * For Ops (amendment 79): the customer unsubscribed — by a marketing email's link or at the
     * newsletter provider — so their marketing consent is withdrawn, at once and everywhere.
     * **Run as the system** (a queued job): the permission is reserved. The job may have been queued
     * by a guest's or a customer's request - the link, the provider's call - never by staff or an
     * integration (as built, amendment 79). Withdrawing what is already
     * off, or an anonymized account, changes nothing. Publishes `MarketingConsentChanged`.
     *
     * @throws DomainError when the source would give consent instead
     */
    public function withdrawMarketingConsent(string $customerId, MarketingConsentSource $source): void;
}
