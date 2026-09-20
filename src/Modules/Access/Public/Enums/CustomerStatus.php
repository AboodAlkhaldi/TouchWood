<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * A customer account's state (Access spec §1.1, §4.1). It controls signing in only: a pending
 * deletion is a separate column, so a blocked customer's deletion still runs.
 */
enum CustomerStatus: string
{
    case Active = 'ACTIVE';

    /** Stopped by staff: cannot sign in, and is told so after the right password (spec §1.8). */
    case Blocked = 'BLOCKED';
}
