<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Inventory\Public\InventoryPermissions as Names;

/**
 * The permissions Inventory's use cases check (inventory.md §3). Declared into Access's catalog at
 * boot.
 *
 * **One permission, per store, any role - Manage Stock** (owner, 2026-10-09): the stock, hand changes,
 * thresholds, the switches and the Low Stock list. It sits in the role editor's Catalog group, as no
 * stock group exists (owner, 2026-10-09). The system's jobs are reserved: never offered in a role.
 *
 * The names themselves are public (`Modules\Inventory\Public\InventoryPermissions`), so another module
 * can check Manage Stock for itself (owner, 2026-10-19); the constants here are the same names, for the
 * module's own use cases.
 *
 * Each permission's handlers arrive with their build step; the module's README says which.
 */
final class InventoryPermissions
{
    /** Stock in a store: stocktakes, hand changes, thresholds, the switches; the Low Stock list. */
    public const string STOCK_MANAGE = Names::STOCK_MANAGE;

    /** The scheduled job that frees expired holds - the system's. */
    public const string HOLDS_EXPIRE = Names::HOLDS_EXPIRE;

    /** The repair job that pushes every size's orderability to Catalog again - the system's. */
    public const string ORDERABLE_REBUILD = Names::ORDERABLE_REBUILD;

    /** The job that counts a store's low sizes again for the menu (step 5) - the system's. */
    public const string LOW_STOCK_COUNT = Names::LOW_STOCK_COUNT;

    /** The nightly job that removes low-stock changes older than twelve months (amendment 1) - the system's. */
    public const string LOW_CHANGES_PRUNE = Names::LOW_CHANGES_PRUNE;

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $jobs = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Catalog),
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
     * @return list<string> the one job a role may hold (§3)
     */
    public static function jobs(): array
    {
        return [self::STOCK_MANAGE];
    }

    /**
     * @return list<string> the system's, never in a role
     */
    public static function reserved(): array
    {
        return [
            self::HOLDS_EXPIRE,
            self::ORDERABLE_REBUILD,
            self::LOW_STOCK_COUNT,
            self::LOW_CHANGES_PRUNE,
        ];
    }
}
