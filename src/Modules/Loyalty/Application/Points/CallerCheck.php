<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Public\Dto\CallerPermission;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The caller's permission for a change to points (loyalty.md §2.1, §3), as Platform checks a module's
 * media (`DeleteMediaHandler`), with one difference: **the scope is Loyalty's**, the store the change
 * touches — for an order Loyalty has recorded, its recorded store — so a caller cannot change one
 * store's points with a permission held in another. The permission must be the calling module's own,
 * and never Loyalty's. An undeclared permission, or a global one, is refused by the authorizer itself.
 */
final readonly class CallerCheck
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized
     */
    public function authorize(CallerPermission $caller, StoreId $store): void
    {
        if ($caller->module === 'loyalty' || ! str_starts_with($caller->permission, $caller->module.'.')) {
            throw new InvalidPointsAttribute('permission', "\"{$caller->permission}\" is not the \"{$caller->module}\" module's own");
        }

        $this->authorizer->authorize($caller->permission, PermissionScope::store($store));
    }
}
