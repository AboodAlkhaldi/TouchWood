<?php

declare(strict_types=1);

namespace Modules\B2B\Public\Enums;

/**
 * A company's status (b2b.md §1.1, §4.1; handoff §8.2) — **these four only**, and the only company
 * status anywhere in the system. It controls ordering, never signing in: a person is stopped by
 * Access's account status, not by this.
 */
enum CompanyStatus: string
{
    /** Sent, waiting for staff. Cannot order; sees company prices, as every company account does. */
    case Pending = 'PENDING';

    /** May order. The only status that may (handoff §7.4). */
    case Approved = 'APPROVED';

    /** Told why, and may send a new application. */
    case Rejected = 'REJECTED';

    /** Stopped by staff, from any status, until they reinstate it to the status it had. */
    case Suspended = 'SUSPENDED';
}
