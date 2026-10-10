<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderWarranties;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\ListOrder;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ListOrders;
use Shared\Application\Unauthorized;

/**
 * **Ordering the warranties** (catalog.md §1.9, amendment 16(d)), under `catalog.warranty.manage`
 * with All stores, under the warranties' lock: the order sent first, the rest after it, each
 * position written again 1, 2, 3 … (P29). Each warranty whose place changed is audited.
 */
final readonly class OrderWarrantiesHandler
{
    public const string PERMISSION = CatalogPermissions::WARRANTY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private ListOrders $orders,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(OrderWarranties $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $sent = ListOrder::sent($command->warrantyIds);

        $this->change->run(ListLocks::WARRANTIES, function () use ($sent): array {
            $changes = ListOrder::changes($this->orders->positions(ListOrders::WARRANTIES), $sent, static fn (string $id): ListItemNotFound => new ListItemNotFound($id));
            $this->orders->write(ListOrders::WARRANTIES, ListOrder::positions($changes));
            $entries = [];

            foreach ($changes as $id => [$was, $now]) {
                $entries[] = ListAudit::changed('warranty', 'ordered', $id, ['position' => $was], ['position' => $now]);
            }

            return [null, array_values(array_filter($entries))];
        });
    }
}
