<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffRole;

use Modules\Access\Public\Enums\AccessLevel;

/**
 * One action with its own stores for this staff member: an exception to their store row.
 */
final readonly class ActionStores
{
    /**
     * @param  list<string>  $storeIds  empty for all stores
     */
    public function __construct(
        public string $permission,
        public AccessLevel $accessLevel,
        public array $storeIds = [],
    ) {}
}
