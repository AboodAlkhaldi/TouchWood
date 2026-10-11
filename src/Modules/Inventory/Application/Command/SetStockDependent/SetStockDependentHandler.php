<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetStockDependent;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StoreWiring;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
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
        private StockRows $stock,
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

        $this->change->run($storeId, function () use ($storeId, $products, $command): array {
            $rows = $this->stock->lockFor($storeId, $products);
            $entries = [];

            foreach (array_keys($products) as $variantId) {
                $before = $rows[$variantId]->stockDependent;

                if ($before === $command->on) {
                    continue;
                }

                $this->stock->setStockDependent($storeId, $variantId, $command->on);
                $entries[] = new AuditEntryDto(
                    'inventory.stock_dependent.set',
                    'inventory.stock',
                    $variantId,
                    $storeId->value,
                    AuditChanges::none()->changed('stock_dependent', $before, $command->on),
                );
            }

            return [null, $entries];
        });
    }
}
