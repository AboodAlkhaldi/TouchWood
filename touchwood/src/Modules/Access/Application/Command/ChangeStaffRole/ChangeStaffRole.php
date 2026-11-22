<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffRole;

use Modules\Access\Public\Enums\AccessLevel;

/**
 * Gives a staff member their one role — a saved role kept as it is, or their personal role — and
 * the stores its actions reach: one store row, and any action's own stores (Access spec §1.5).
 * Exactly one of $savedRoleId and $personalRole is given.
 */
final readonly class ChangeStaffRole
{
    /**
     * @param  list<string>  $storeIds  empty for all stores
     * @param  list<ActionStores>  $exceptions
     */
    public function __construct(
        public string $staffId,
        public AccessLevel $accessLevel,
        public array $storeIds = [],
        public array $exceptions = [],
        public ?string $savedRoleId = null,
        public ?PersonalRole $personalRole = null,
    ) {}
}
