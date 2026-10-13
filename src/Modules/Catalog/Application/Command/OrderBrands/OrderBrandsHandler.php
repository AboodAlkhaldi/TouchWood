<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderBrands;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\ListOrder;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ListOrders;
use Shared\Application\Unauthorized;

/**
 * **Ordering the brands** (catalog.md §1.6, amendment 16(d)), under `catalog.brand.manage` with All
 * stores, under the brands' lock: the order sent first, the rest after it, each position written
 * again 1, 2, 3 … (P29). Each brand whose place changed is audited; an order that moves nothing
 * writes and records nothing. A brand's fixed number never moves with it (amendment 7(b)).
 */
final readonly class OrderBrandsHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private ListOrders $orders,
    ) {}

    /**
     * @throws BrandNotFound|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(OrderBrands $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $sent = ListOrder::sent($command->brandIds);

        $this->change->run(ListLocks::BRANDS, function () use ($sent): array {
            $changes = ListOrder::changes($this->orders->positions(ListOrders::BRANDS), $sent, static fn (string $id): BrandNotFound => new BrandNotFound($id));
            $this->orders->write(ListOrders::BRANDS, ListOrder::positions($changes));
            $entries = [];

            // Each item whose place changed; one only numbered again where it stands is not moved.
            foreach (ListOrder::moved($changes) as $id => [$was, $now]) {
                $entries[] = ListAudit::changed('brand', 'ordered', $id, ['position' => $was], ['position' => $now]);
            }

            return [null, array_values(array_filter($entries))];
        });
    }
}
