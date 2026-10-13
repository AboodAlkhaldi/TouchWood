<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderAttributeValues;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\ListOrder;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ListOrders;
use Shared\Application\Unauthorized;

/**
 * **Ordering one attribute's values** (catalog.md §1.7, amendment 16(d)), under
 * `catalog.attribute.manage` with All stores, under the attributes' lock: the order sent first, the
 * rest of its values after it, each position written again 1, 2, 3 … (P29). The attribute's row is
 * locked before its values are written, the order of every change that locks both (a product's
 * too). A value of another attribute is not one of this attribute's, and is answered as not found.
 * Each value whose place changed is audited.
 */
final readonly class OrderAttributeValuesHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private ListOrders $orders,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(OrderAttributeValues $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $sent = ListOrder::sent($command->valueIds);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $sent): array {
            $attribute = $this->attributes->byId($command->attributeId) ?? throw new ListItemNotFound($command->attributeId);
            $changes = ListOrder::changes($this->orders->positions(ListOrders::VALUES, $attribute->id()), $sent, static fn (string $id): ListItemNotFound => new ListItemNotFound($id));
            $this->orders->write(ListOrders::VALUES, ListOrder::positions($changes));
            $entries = [];

            // Each item whose place changed; one only numbered again where it stands is not moved.
            foreach (ListOrder::moved($changes) as $id => [$was, $now]) {
                $entries[] = ListAudit::changed('attribute_value', 'ordered', $id, ['position' => $was], ['position' => $now]);
            }

            return [null, array_values(array_filter($entries))];
        });
    }
}
