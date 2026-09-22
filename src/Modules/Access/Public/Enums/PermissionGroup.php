<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * The business area an action belongs to, so the role editor and the admin menu group actions the
 * way staff think of them rather than by the module that happens to own them (stage 2b, P2 and P6;
 * handoff §14). One list serves both (owner, 2026-09-22).
 *
 * Every group is named in Arabic and English at `access::permission_groups.{value}`.
 *
 * A permission needs a group only if a role can hold it: reserved actions and the ones everybody of
 * a kind holds automatically are never offered in the editor and never appear in the menu.
 */
enum PermissionGroup: string
{
    case StaffAndPermissions = 'staff_and_permissions';

    case Customers = 'customers';

    case StoreSettings = 'store_settings';

    case Media = 'media';

    case Audit = 'audit';

    // Nothing declares these yet; they are the groups the modules still to be built will use
    // (handoff §14 and the design's own grouping).
    case Catalog = 'catalog';

    case Pricing = 'pricing';

    case Orders = 'orders';

    case Companies = 'companies';

    public function labelKey(): string
    {
        return "access::permission_groups.{$this->value}";
    }
}
