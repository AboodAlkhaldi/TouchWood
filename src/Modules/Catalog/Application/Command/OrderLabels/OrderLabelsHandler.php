<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderLabels;

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
 * **Ordering the labels** (catalog.md §1.8, amendment 16(d)), under `catalog.label.manage` with All
 * stores, under the labels' lock: the order sent first, the rest after it, each position written
 * again 1, 2, 3 … (P29). Every card shows its labels in this order as it is read, so no listing row
 * is written again. Each label whose place changed is audited.
 */
final readonly class OrderLabelsHandler
{
    public const string PERMISSION = CatalogPermissions::LABEL_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private ListOrders $orders,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(OrderLabels $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $sent = ListOrder::sent($command->labelIds);

        $this->change->run(ListLocks::LABELS, function () use ($sent): array {
            $changes = ListOrder::changes($this->orders->positions(ListOrders::LABELS), $sent, static fn (string $id): ListItemNotFound => new ListItemNotFound($id));
            $this->orders->write(ListOrders::LABELS, ListOrder::positions($changes));
            $entries = [];

            foreach ($changes as $id => [$was, $now]) {
                $entries[] = ListAudit::changed('label', 'ordered', $id, ['position' => $was], ['position' => $now]);
            }

            return [null, array_values(array_filter($entries))];
        });
    }
}
