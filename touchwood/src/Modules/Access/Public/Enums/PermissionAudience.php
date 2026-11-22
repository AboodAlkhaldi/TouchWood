<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * Who holds a permission (Access spec §1.5, owner's decision 2026-09-19).
 */
enum PermissionAudience: string
{
    /** Staff, only through their role. The only kind shown in the role editor. */
    case Role = 'ROLE';

    /** Every active staff member, automatically, for their own account. */
    case EveryStaff = 'EVERY_STAFF';

    /** Every active customer, automatically, for their own data. */
    case EveryCustomer = 'EVERY_CUSTOMER';

    /** Every visitor who is not signed in, automatically. */
    case EveryGuest = 'EVERY_GUEST';
}
