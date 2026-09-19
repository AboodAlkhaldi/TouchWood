<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffRole;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\ValueObject\StoreChoice;
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

    /**
     * @param  list<self>  $exceptions
     * @return array<string, StoreChoice> permission => its stores
     */
    public static function toChoices(array $exceptions): array
    {
        $byPermission = [];

        foreach ($exceptions as $exception) {
            if (isset($byPermission[$exception->permission])) {
                throw new InvalidAccessAttribute('exceptions', "\"{$exception->permission}\" is given its own stores twice");
            }

            $byPermission[$exception->permission] = StoreChoice::of($exception->accessLevel, $exception->storeIds);
        }

        return $byPermission;
    }
}
