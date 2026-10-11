<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * The permissions Loyalty declares (loyalty.md §3). Declared into Access's catalog at boot.
 *
 * A customer reads their own points with one automatic permission. Staff read a store's points with
 * one ordinary job; changing points by hand and changing the programme's settings are **admin-only**
 * (owner, 2026-10-07: points cost the business money). All three sit in the Points group. The nightly
 * expiry is the system's, reserved and store-free.
 *
 * The writes Sales makes — redeeming, earning, settling — check **Sales's** permission, named by the
 * caller (§2.1); Loyalty declares nothing for them.
 */
final class LoyaltyPermissions
{
    /** A customer's own points, in every store they have points in. */
    public const string VIEW_OWN = 'loyalty.points.view_own';

    /** A store's points: a customer's points there, and the store's redemptions. */
    public const string VIEW = 'loyalty.points.view';

    /** Add or remove a customer's points by hand, with a reason (§1.9). */
    public const string ADJUST = 'loyalty.points.adjust';

    /** Change the store's points programme (§1.5). */
    public const string SETTINGS_UPDATE = 'loyalty.settings.update';

    /** The nightly expiry (§1.10): the system only. */
    public const string EXPIRE = 'loyalty.points.expire';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $own = new PermissionDefinitionDto(self::VIEW_OWN, PermissionAudience::EveryCustomer, kind: PermissionKind::Global);

        $jobs = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Points, adminOnly: in_array($name, self::adminOnly(), true)),
            self::jobs(),
        );

        $reserved = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
            self::reserved(),
        );

        return [$own, ...$jobs, ...$reserved];
    }

    /**
     * @return list<string> the three jobs a role may hold (§3)
     */
    public static function jobs(): array
    {
        return [self::VIEW, self::ADJUST, self::SETTINGS_UPDATE];
    }

    /**
     * @return list<string> the jobs only an admin role may hold (owner, 2026-10-07)
     */
    public static function adminOnly(): array
    {
        return [self::ADJUST, self::SETTINGS_UPDATE];
    }

    /**
     * @return list<string> Super Admin and the system only, never in a role
     */
    public static function reserved(): array
    {
        return [self::EXPIRE];
    }
}
