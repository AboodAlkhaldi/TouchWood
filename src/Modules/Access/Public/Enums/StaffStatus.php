<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * A staff account's state (Access spec §4.3). Staff are never deleted: the audit log names them
 * forever.
 */
enum StaffStatus: string
{
    /** Invited, has not set a password yet. Holds no permission. */
    case Invited = 'INVITED';

    case Active = 'ACTIVE';

    /** Left, or stopped. Holds no permission; can be enabled again. */
    case Disabled = 'DISABLED';
}
