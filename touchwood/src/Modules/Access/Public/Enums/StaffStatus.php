<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * A staff account's state (Access spec §4.3). Staff are never deleted: the audit log names them
 * forever.
 */
enum StaffStatus: string
{
    /** Not registered yet: invited, has not accepted. Holds no permission. */
    case Invited = 'INVITED';

    case Active = 'ACTIVE';

    /** Accepted once, now stopped (left, or suspended). Holds no permission; can be enabled again. */
    case Disabled = 'DISABLED';

    /** An invitation withdrawn for good: final; the email and phone are free for someone else. */
    case Cancelled = 'CANCELLED';
}
