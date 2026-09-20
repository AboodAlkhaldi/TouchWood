<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DisableStaff;

/**
 * A staff member who left: they hold nothing and cannot sign in; never deleted (spec §1.4). Their
 * open sessions end for good, with every link, code and trusted browser.
 */
final readonly class DisableStaff
{
    public function __construct(
        public string $staffId,
    ) {}
}
