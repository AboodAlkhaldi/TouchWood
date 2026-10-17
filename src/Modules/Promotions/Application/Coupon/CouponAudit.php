<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * The audit entries of staff changes to coupons (promotions.md §3): `promotions.coupon.created`,
 * `…updated`, `…deactivated`, `…activated`, `…deleted`, each with what changed, attribute by attribute.
 */
final class CouponAudit
{
    /**
     * @param  array<string, bool|int|string|list<string>|null>  $from
     * @param  array<string, bool|int|string|list<string>|null>  $to
     */
    public static function entry(string $action, string $couponId, string $storeId, array $from, array $to): ?AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $attribute) {
            if (($from[$attribute] ?? null) !== ($to[$attribute] ?? null)) {
                $changes = $changes->changed($attribute, $from[$attribute] ?? null, $to[$attribute] ?? null);
            }
        }

        return $changes->isEmpty() ? null : new AuditEntryDto("promotions.coupon.{$action}", 'promotions.coupon', $couponId, $storeId, $changes);
    }
}
