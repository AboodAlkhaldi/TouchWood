<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetStockDependent;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StockDependentSwitch;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Shared\Application\Unauthorized;

/**
 * **The stock-dependent switch** (inventory.md §1.2, §3, §4.2), under **Manage Stock** in a wired
 * store: on or off for the sizes picked - all of a product's, some, or one (owner, 2026-10-09). Off by
 * default; a size added later starts off. A store with no provider counts every product on its stock
 * already, so the switch is refused there. Each size that changed is audited from and to.
 */
final readonly class SetStockDependentHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private StoreWiring $wiring,
        private SizeInput $sizes,
        private StockDependentSwitch $switch,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|StoreOff|NotWired
     */
    public function handle(SetStockDependent $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if (! $this->wiring->isWired($storeId)) {
            throw new NotWired;
        }

        $products = [];

        foreach ($this->sizes->check($command->variantIds) as [$variantId, $productId]) {
            $products[$variantId] = $productId;
        }

        if ($products === []) {
            return;
        }

        // The switch itself is shared with InventoryApi::makeStockDependent (StockDependentSwitch).
        $this->change->run($storeId, fn (): array => [null, $this->switch->set($storeId, $products, $command->on)[1]]);
    }
}
