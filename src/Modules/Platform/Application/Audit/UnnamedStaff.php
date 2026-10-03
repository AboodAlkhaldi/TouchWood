<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Audit;

use Modules\Platform\Public\Contracts\StaffNames;

/**
 * Nobody named: Platform's own answer until the module that owns people binds StaffNames (Access
 * does, access.md amendment 54). An entry then shows the id it holds, as it always did.
 */
final class UnnamedStaff implements StaffNames
{
    public function forReader(array $ids): array
    {
        return [];
    }
}
