<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetLowStockThreshold;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Exception\ThresholdInvalid;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **A size's own low-stock threshold** (inventory.md §1.2, §1.7, §3), under **Manage Stock** in that
 * store - wired or not, as it only ever alerts staff (§1.1 rule 4). From 0 to 100,000 (owner,
 * 2026-10-10); null gives the size back the store's default. Each threshold that changed is audited
 * from and to; one that is the same changes nothing.
 */
final readonly class SetLowStockThresholdHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private SizeInput $sizes,
        private StockRows $stock,
        private StockFacts $facts,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|StoreOff|ThresholdInvalid
     */
    public function handle(SetLowStockThreshold $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();
        $own = $this->sizes->check(array_map('strval', array_keys($command->thresholds)));

        /** @var array<string, int|null> $thresholds */
        $thresholds = [];
        /** @var array<string, string> $products */
        $products = [];

        foreach ($command->thresholds as $given => $threshold) {
            [$variantId, $productId] = $own[(string) $given];

            if ($threshold !== null && ($threshold < 0 || $threshold > StockLimits::THRESHOLD_MOST)) {
                throw new ThresholdInvalid([(string) $given]);
            }

            if (array_key_exists($variantId, $thresholds) && $thresholds[$variantId] !== $threshold) {
                throw new InvalidInventoryInput('variant', [(string) $given]);
            }

            $thresholds[$variantId] = $threshold;
            $products[$variantId] = $productId;
        }

        if ($thresholds === []) {
            return;
        }

        $this->change->run($storeId, function () use ($storeId, $thresholds, $products): array {
            $rows = $this->stock->lockExisting($storeId, array_map('strval', array_keys($thresholds)));
            // A size with no row has the store's default: a row is made only for a size given its own,
            // so clearing a threshold that was never set adds nothing to the Low Stock list (§1.7).
            $rows += $this->stock->lockFor($storeId, array_filter(
                $products,
                static fn (string $variantId): bool => ! isset($rows[$variantId]) && $thresholds[$variantId] !== null,
                ARRAY_FILTER_USE_KEY,
            ));
            $entries = [];

            foreach ($thresholds as $variantId => $threshold) {
                $before = isset($rows[$variantId]) ? $rows[$variantId]->threshold : null;

                if ($before === $threshold) {
                    continue;
                }

                $this->stock->setThreshold($storeId, $variantId, $threshold);
                $entries[] = new AuditEntryDto(
                    'inventory.stock.threshold_set',
                    'inventory.stock',
                    $variantId,
                    $storeId->value,
                    AuditChanges::none()->changed('threshold', $before, $threshold),
                );
            }

            // Catalog told of what flipped, inside this transaction (§1.3, §2.3).
            $this->facts->refresh($storeId, array_map('strval', array_keys($thresholds)));

            return [null, $entries];
        });
    }
}
