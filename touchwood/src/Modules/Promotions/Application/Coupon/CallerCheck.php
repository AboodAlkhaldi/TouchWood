<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Dto\CallerPermission;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The caller's permission for a write (promotions.md §2.1, §3), as Loyalty's (loyalty.md §2.1): **the
 * scope is Promotions'** — the coupon's own store, the order's — so a caller cannot write one store's
 * coupons with a permission held in another. The permission must be the calling module's own, and
 * never Promotions'. An undeclared permission, or a global one, is refused by the authorizer itself.
 */
final readonly class CallerCheck
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    /**
     * @throws InvalidPromotionAttribute|Unauthorized
     */
    public function authorize(CallerPermission $caller, StoreId $store): void
    {
        if ($caller->module === 'promotions' || ! str_starts_with($caller->permission, $caller->module.'.')) {
            throw new InvalidPromotionAttribute('permission', "\"{$caller->permission}\" is not the \"{$caller->module}\" module's own");
        }

        $this->authorizer->authorize($caller->permission, PermissionScope::store($store));
    }
}
