<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * The stores a staff member's actions reach (handoff §7.5, Access spec §1.5).
 */
enum AccessLevel: string
{
    /** Every store, now and opened later. The only choice that passes an "every store" check. */
    case AllStores = 'ALL_STORES';

    /** Only the listed stores. */
    case SelectedStores = 'SELECTED_STORES';
}
