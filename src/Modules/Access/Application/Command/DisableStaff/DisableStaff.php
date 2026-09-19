<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DisableStaff;

/**
 * A staff member who left: they hold nothing and cannot sign in; never deleted (spec §1.4). Their
 * open sessions and trusted browsers end with staff sign-in (step 3b).
 */
final readonly class DisableStaff
{
    public function __construct(
        public string $staffId,
    ) {}
}
