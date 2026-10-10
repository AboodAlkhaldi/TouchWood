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

    /** The running of the system — first the failed jobs (owner, 2026-09-29; frontend.md E7). */
    case System = 'system';

    /** Catalog's jobs (catalog.md §3, from 2026-10-02). */
    case Catalog = 'catalog';

    /** Pricing's jobs (pricing.md §3, from 2026-10-10). */
    case Pricing = 'pricing';

    /** For Sales, still to be built (handoff §14 and the design's own grouping). */
    case Orders = 'orders';

    /** B2B's staff jobs (b2b.md amendment 10). */
    case Companies = 'companies';

    public function labelKey(): string
    {
        return "access::permission_groups.{$this->value}";
    }
}
