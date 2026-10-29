<?php

declare(strict_types=1);

namespace Modules\Shipping\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * Shipping's permissions (shipping.md §3), each per store, in the role editor's Shipping group
 * (access.md amendment 77). Admins manage carriers and prices (owner, 2026-10-27 S12); boxes are an
 * admin's by default and any staff role's it is given to (owner, 2026-10-28); the carriers are read
 * by any role given the view - a plain role permission, no default (owner, 2026-10-29, step 3 Q1).
 */
final class ShippingPermissions
{
    /** Create, change, switch, order and delete the store's carriers. */
    public const string CARRIERS_MANAGE = 'shipping.carriers.manage';

    /** The store's carriers, a carrier's prices, the places no carrier serves. */
    public const string CARRIERS_VIEW = 'shipping.carriers.view';

    /** A carrier's price groups and the free-shipping amounts (step 4). */
    public const string PRICES_MANAGE = 'shipping.prices.manage';

    /** The box list, the padding, a size's packing (step 5). */
    public const string BOXES_MANAGE = 'shipping.boxes.manage';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $role = static fn (string $name, bool $adminOnly = false): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, adminOnly: $adminOnly, kind: PermissionKind::PerStore, group: PermissionGroup::Shipping);

        return [
            $role(self::CARRIERS_VIEW),
            $role(self::CARRIERS_MANAGE, adminOnly: true),
            $role(self::PRICES_MANAGE, adminOnly: true),
            $role(self::BOXES_MANAGE),
        ];
    }
}
