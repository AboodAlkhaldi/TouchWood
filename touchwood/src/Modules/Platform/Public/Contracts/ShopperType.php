<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Enums\ShopperKind;

/**
 * **Who is looking at the shop** in this request — a guest, an individual or a company (catalog.md
 * §1.13, §2.4; owner, 2026-10-07, amendment 14(a)).
 *
 * Platform knows no customers: Access owns them, and **Access binds this**, from the signed-in
 * customer's account type, as it binds `StaffNames`. A module that shows the shop — Catalog — asks
 * here and never reaches into Access (handoff §4.4). Until a module binds it, everyone is a guest
 * (Platform's own `GuestShoppers`).
 *
 * The account type alone decides: a company account is a company whatever its company's state in
 * a store (pending, rejected, suspended — handoff §6). A staff member viewing the shop from the panel
 * is a guest (access.md §1.11); so is the system.
 */
interface ShopperType
{
    public function current(): ShopperKind;
}
