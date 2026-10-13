<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Modules\Access\Public\Enums\AccountType;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * **Who is looking at the shop**, for Platform's `ShopperType` (catalog.md §1.13, owner 2026-10-07,
 * amendment 14(a)): Platform sits below Access and cannot ask it, so Access binds Platform's contract,
 * as it binds `StaffNames`.
 *
 * The customer this request acts as decides it by their account type alone — a company account is a
 * company whatever its company's state in a store (handoff §6). Anyone else is a guest: nobody signed
 * in, **a staff member viewing the shop** (spec §1.11: while a staff view holds, no customer is
 * identified, so the request acts as a guest), and the system.
 *
 * An account's type never changes (§1.1, a trigger holds it), so the answer is kept by customer id for
 * the life of this instance — read through RequestCustomers, the same read the shop's frame names them
 * from, so one read a customer, never a stale one; a request that signs someone in
 * asks again for the new id.
 */
final class CustomerShopperType implements ShopperType
{
    /** @var array<string, ShopperKind> customer id => their kind */
    private array $known = [];

    public function __construct(
        private readonly ActorContext $actors,
        private readonly RequestCustomers $customers,
    ) {}

    public function current(): ShopperKind
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            return ShopperKind::Guest;
        }

        return $this->known[$actor->id] ??= match (AccountType::tryFrom((string) ($this->customers->customer($actor->id)['account_type'] ?? ''))) {
            AccountType::Individual => ShopperKind::Individual,
            AccountType::Company => ShopperKind::Company,
            // An account no longer there is nobody: what a guest sees.
            null => ShopperKind::Guest,
        };
    }
}
