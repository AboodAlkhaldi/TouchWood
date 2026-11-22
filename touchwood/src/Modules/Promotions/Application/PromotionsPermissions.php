<?php

declare(strict_types=1);

namespace Modules\Promotions\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * Promotions' permissions (promotions.md §3): four jobs a role may hold, per store, under Marketing —
 * ordinary jobs an admin may give a marketing role (owner, 2026-10-08); a customer's own coupons; the
 * nightly refresh of segments, reserved to the system.
 *
 * No setting of its own: the discount ceiling's number is Pricing's, changed under
 * `pricing.ceiling.manage` (owner, 2026-10-13 and 2026-10-17 — `promotions.settings.update` dropped).
 */
final class PromotionsPermissions
{
    /** A store's coupons, segments and gift levels. */
    public const string VIEW = 'promotions.marketing.view';

    /** Create, change, switch, delete and assign a store's coupons. */
    public const string COUPON_MANAGE = 'promotions.coupon.manage';

    /** Create, change and delete a store's segments; pick their customers; refresh them now. */
    public const string SEGMENT_MANAGE = 'promotions.segment.manage';

    /** Create, change and switch off a store's gift levels. */
    public const string GIFT_MANAGE = 'promotions.gift.manage';

    /** A customer's own coupons, in the account centre. */
    public const string VIEW_OWN = 'promotions.coupon.view_own';

    /** The nightly refresh of every segment: the system only. */
    public const string SEGMENT_REFRESH = 'promotions.segment.refresh';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $jobs = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Marketing),
            self::jobs(),
        );

        return [
            ...$jobs,
            new PermissionDefinitionDto(self::VIEW_OWN, PermissionAudience::EveryCustomer, kind: PermissionKind::Global),
            new PermissionDefinitionDto(self::SEGMENT_REFRESH, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
        ];
    }

    /**
     * @return list<string> the four jobs a role may hold (§3)
     */
    public static function jobs(): array
    {
        return [self::VIEW, self::COUPON_MANAGE, self::SEGMENT_MANAGE, self::GIFT_MANAGE];
    }
}
