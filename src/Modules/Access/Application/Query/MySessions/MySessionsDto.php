<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MySessions;

/**
 * What the sessions screen shows one staff member about themselves.
 */
final readonly class MySessionsDto
{
    /**
     * @param  list<StaffSessionDto>  $sessions  most recently seen first
     * @param  list<TrustedBrowserDto>  $trustedBrowsers  newest first, expired ones left out
     */
    public function __construct(
        public array $sessions,
        public array $trustedBrowsers,
    ) {}
}
