<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetEndingSoon;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\ProviderOwnsStock;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **"Last pieces" for a product** (inventory.md §1.2, §3), under **Manage Stock** in that store with no
 * provider: switched on, its sizes show "ending soon" once their available stock is at or below the
 * threshold. A wired store decides it by itself, on stock-dependent sizes only, so the switch is
 * refused there. An archived product is read-only until restored (owner, 2026-10-11). Audited from
 * and to; the same value changes nothing.
 */
final readonly class SetEndingSoonHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private StoreWiring $wiring,
        private CatalogApi $catalog,
        private StoreProducts $products,
        private StockFacts $facts,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|StoreOff|ProviderOwnsStock
     */
    public function handle(SetEndingSoon $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if ($this->wiring->isWired($storeId)) {
            throw new ProviderOwnsStock;
        }

        $product = $this->catalog->products([$command->productId])[strtolower(trim($command->productId))] ?? null;

        if ($product === null || $product->stage === ProductStage::Archived) {
            throw new InvalidInventoryInput('product');
        }

        $this->change->run($storeId, function () use ($storeId, $product, $command): array {
            $before = $this->products->endingSoon($storeId, $product->id);

            if ($before === $command->on) {
                return [null, []];
            }

            $this->products->setEndingSoon($storeId, $product->id, $command->on);
            // Catalog told of the product's sizes that flipped (§1.2, §2.3).
            $this->facts->refresh($storeId, $this->catalog->variantIdsOf($product->id));

            return [null, [new AuditEntryDto(
                'inventory.store_product.ending_soon_set',
                'inventory.store_product',
                $product->id,
                $storeId->value,
                AuditChanges::none()->changed('ending_soon', $before, $command->on),
            )]];
        });
    }
}
