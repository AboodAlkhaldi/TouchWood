<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListCustomerOrders;

use Modules\Sales\Application\Query\Staff\OrderStores;
use Modules\Sales\Application\Query\Staff\StaffOrderRow;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **A customer's latest orders, for staff** (sales.md §3.2; the staff customer page's `sales.orders`
 * section): the 10 newest in the stores the reader views orders in — as `ListOrders` lists them, an off
 * store's only while unfinished — "See all" opening `ListOrders` with the customer.
 */
final readonly class ListCustomerOrdersHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    public const int LATEST = 10;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private StaffReads $reads,
        private StaffReader $reader,
        private OrderStores $stores,
    ) {}

    /**
     * @return list<StaffOrderRow>
     *
     * @throws Unauthorized
     */
    public function handle(ListCustomerOrders $query): array
    {
        $covered = $this->authorizer->storesWith(self::PERMISSION);

        if ($this->actors->current()->type !== ActorType::Staff || $covered === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->authorizer->authorize(self::PERMISSION, $covered === null ? PermissionScope::allStores() : PermissionScope::store($covered[0]));
        $customerId = strtolower(trim($query->customerId));

        if (! Ulids::valid($customerId)) {
            return [];
        }

        $rows = $this->reads->orders($this->stores->ids($covered), StaffReads::ALL, null, self::LATEST, null, $customerId);
        $customer = $this->reader->customers([$customerId])[$customerId];

        return array_map(fn (array $row): StaffOrderRow => $this->reader->orderRow($row, $customer), $rows);
    }
}
