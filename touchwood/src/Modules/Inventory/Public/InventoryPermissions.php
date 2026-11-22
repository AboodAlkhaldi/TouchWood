<?php

declare(strict_types=1);

namespace Modules\Inventory\Public;

/**
 * The names of Inventory's permissions (inventory.md §3), public so another module can name the one it
 * checks for itself - Promotions asks for **Manage Stock** in the store before its gift level's tick box
 * turns a size's stock-dependent switch on (owner, 2026-10-19; inventory.md §9.2). What each permission
 * is - its group, its kind, whether a role may hold it - is declared by
 * `Modules\Inventory\Application\InventoryPermissions`, which takes its names from here.
 */
final class InventoryPermissions
{
    /** Stock in a store: stocktakes, hand changes, thresholds, the switches; the Low Stock list. */
    public const string STOCK_MANAGE = 'inventory.stock.manage';

    /** The scheduled job that frees expired holds - the system's. */
    public const string HOLDS_EXPIRE = 'inventory.holds.expire';

    /** The repair job that pushes every size's orderability to Catalog again - the system's. */
    public const string ORDERABLE_REBUILD = 'inventory.orderable.rebuild';

    /** The job that counts a store's low sizes again for the menu (step 5) - the system's. */
    public const string LOW_STOCK_COUNT = 'inventory.low_stock.count';

    /** The nightly job that removes low-stock changes older than twelve months (amendment 1) - the system's. */
    public const string LOW_CHANGES_PRUNE = 'inventory.low_changes.prune';
}
