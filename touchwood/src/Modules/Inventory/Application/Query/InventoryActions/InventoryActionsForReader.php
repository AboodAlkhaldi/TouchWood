<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\InventoryActions;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\ReaderStores;
use Modules\Inventory\Application\Stock\Words;
use Modules\Inventory\Public\Contracts\StoreWiring;

/**
 * **What Inventory's screens may offer** (inventory.md §4.3.3; pricing.md §4.3.1 "Who may do what"): for
 * each store where the reader holds **Manage Stock** - an off store only for whoever may switch stores
 * (Q1) - whether they may open products, whether the store is wired or off, and the reasons a button is
 * drawn disabled.
 *
 * The answer is **Inventory's, not the screen's** - a controller that asked the authorizer itself would
 * be deciding, and a test forbids it (tests/Architecture/AccessDecisionsTest) - and every handler asks
 * again anyway: offering is never allowing (handoff §19). It reads; it never locks.
 */
final readonly class InventoryActionsForReader
{
    /** Catalog's job to open a product's page (catalog.md §3), named here as a value: Inventory reads none of Catalog's inside. */
    public const string PRODUCT_VIEW = 'catalog.product.view';

    public function __construct(
        private ReaderStores $stores,
        private StoreWiring $wiring,
        private Words $words,
    ) {}

    /**
     * @return list<StoreInventoryActions> in the stores' order
     */
    public function stores(): array
    {
        return $this->answer(null);
    }

    public function forStore(string $storeId): ?StoreInventoryActions
    {
        return $this->answer(strtolower(trim($storeId)))[0] ?? null;
    }

    public function reasons(): InventoryReasons
    {
        return new InventoryReasons(
            $this->words->line('admin_stock.reasons.wired'),
            $this->words->line('admin_stock.reasons.archived'),
        );
    }

    /**
     * @return list<StoreInventoryActions>
     */
    private function answer(?string $only): array
    {
        $held = $this->stores->authorize([InventoryPermissions::STOCK_MANAGE, self::PRODUCT_VIEW]);
        $wiredStores = array_fill_keys(array_map('strtolower', $this->wiring->wiredStores()), true);
        $actions = [];

        foreach ($held as $id => ['store' => $store, 'jobs' => $jobs]) {
            // An Inventory screen is offered for Manage Stock; the products alone open none.
            if (! in_array(InventoryPermissions::STOCK_MANAGE, $jobs, true) || ($only !== null && (string) $id !== $only)) {
                continue;
            }

            $wired = isset($wiredStores[$id]);
            $actions[] = new StoreInventoryActions(
                (string) $id,
                true,
                in_array(self::PRODUCT_VIEW, $jobs, true),
                $wired,
                ! $store->isActive,
                $wired ? $this->words->line('admin_stock.reasons.wired') : null,
            );
        }

        return $actions;
    }
}
