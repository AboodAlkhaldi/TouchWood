<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MySessions;

/**
 * One browser a staff member is signed in on.
 *
 * The address and the browser string are their own, on their own screen: this is how somebody
 * recognises the machine they left signed in at home. They are never shown to anybody else.
 */
final readonly class StaffSessionDto
{
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public ?string $userAgent,
        /** ISO 8601, so the page decides how to say it. */
        public string $lastActivity,
        /** The one reading this page, which ending signs them out of. */
        public bool $isCurrent,
    ) {}
}
