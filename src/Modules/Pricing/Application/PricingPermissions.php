<?php

declare(strict_types=1);

namespace Modules\Pricing\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * The permissions Pricing's use cases check (pricing.md §3). Declared into Access's catalog at boot.
 *
 * **Three jobs, per store, any role** - staff or admin (owner, 2026-10-08) - in the role editor's
 * Pricing group, their names accepted by the owner (2026-10-08). The screens' reads take any of the
 * three in that store. The system's three jobs are reserved: never offered in a role.
 *
 * Each permission's handlers arrive with their build step; the module's README says which.
 */
final class PricingPermissions
{
    /** Retail prices and sales: set and remove a price, add, change, end and remove a sale; Needs a Price. */
    public const string PRICE_EDIT = 'pricing.price.edit';

    /** A size's wholesale quantity bands. */
    public const string WHOLESALE_EDIT = 'pricing.wholesale.edit';

    /** Category discounts: preview, add, change, end, remove. */
    public const string CATEGORY_DISCOUNT_MANAGE = 'pricing.category_discount.manage';

    /** The repair job that rewrites every size's price candidates - the system's. */
    public const string CANDIDATES_REBUILD = 'pricing.candidates.rebuild';

    /** The timed task at a sale's or a discount's start and end - the system's. */
    public const string WINDOWS_APPLY = 'pricing.windows.apply';

    /** The daily safety check over the windows that opened or closed - the system's. */
    public const string WINDOWS_CHECK = 'pricing.windows.check';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $jobs = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Pricing),
            self::jobs(),
        );

        // Reserved and store-free: a system job belongs to no store.
        $reserved = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
            self::reserved(),
        );

        return [...$jobs, ...$reserved];
    }

    /**
     * @return list<string> the three jobs a role may hold (§3)
     */
    public static function jobs(): array
    {
        return [
            self::PRICE_EDIT,
            self::WHOLESALE_EDIT,
            self::CATEGORY_DISCOUNT_MANAGE,
        ];
    }

    /**
     * @return list<string> the system's, never in a role
     */
    public static function reserved(): array
    {
        return [
            self::CANDIDATES_REBUILD,
            self::WINDOWS_APPLY,
            self::WINDOWS_CHECK,
        ];
    }
}
