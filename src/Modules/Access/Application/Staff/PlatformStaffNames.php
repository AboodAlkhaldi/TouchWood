<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Modules\Platform\Public\Contracts\StaffNames;
use Modules\Platform\Public\Dto\StaffNameDto;

/**
 * Platform's audit log names its staff actors through this (access.md amendment 54). Platform sits
 * below Access and cannot ask it; Access binds Platform's contract instead, answered by the same one
 * place every other module's names come from (StaffDisplayNames).
 */
final readonly class PlatformStaffNames implements StaffNames
{
    public function __construct(
        private StaffDisplayNames $names,
    ) {}

    public function forReader(array $ids): array
    {
        $named = [];

        foreach ($this->names->forReader($ids) as $id => $person) {
            $named[$id] = new StaffNameDto($person->name, $person->systemAdministrator);
        }

        return $named;
    }
}
