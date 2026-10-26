<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOrders;

use Modules\Sales\Application\Query\Staff\OrderStores;
use Modules\Sales\Application\Query\Staff\StaffOrderRow;
use Modules\Sales\Application\Query\Staff\StaffOrdersPage;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **Staff's order lists** (sales.md §3 ListOrders; handoff §14: Sales › All orders · Store orders · Unpaid
 * orders): one store's, or every store the reader covers — all, unpaid (holding less than is due), flagged
 * short of stock, waiting for approval, to ship, shipped, or bank transfers to check (§3.2) — searched by an
 * order number, a customer's name or a phone, one customer's alone if asked — newest first, 50 a page, each with its four machines and its customer by name. An off
 * store is listed only while it holds an unfinished order, but to a Super Admin (§1.12: `OrderStores`).
 */
final readonly class ListOrdersHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    /** Orders a page. */
    public const int PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private StaffReads $reads,
        private StaffReader $reader,
        private OrderStores $stores,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(ListOrders $query): StaffOrdersPage
    {
        $covered = $this->authorizer->storesWith(self::PERMISSION);

        if ($this->actors->current()->type !== ActorType::Staff || $covered === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $filter = in_array($query->filter, StaffReads::FILTERS, true) ? $query->filter : throw new InvalidSalesAttribute('filter', implode(', ', StaffReads::FILTERS));
        $q = $query->q === null ? '' : trim($query->q);
        $q = mb_strlen($q) > 100 ? throw new InvalidSalesAttribute('search', 'at most 100 characters') : (mb_strlen($q) < 2 ? null : $q);
        $customerId = $query->customerId === null ? null : strtolower(trim($query->customerId));

        if ($customerId !== null && ! Ulids::valid($customerId)) {
            return new StaffOrdersPage([], null);
        }

        if ($query->storeId !== null) {
            $store = Ulids::valid(strtolower(trim($query->storeId))) ? StoreId::fromString(strtolower(trim($query->storeId))) : throw new InvalidSalesAttribute('store', 'a store');
            $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
            $stores = in_array($store->value, $this->stores->ids([$store]) ?? [$store->value], true) ? [$store->value] : throw new Unauthorized(self::PERMISSION);
        } else {
            $this->authorizer->authorize(self::PERMISSION, $covered === null ? PermissionScope::allStores() : PermissionScope::store($covered[0]));
            $stores = $this->stores->ids($covered);
        }

        $after = $query->after === null ? null : strtolower(trim($query->after));

        if ($after !== null && ! Ulids::valid($after)) {
            return new StaffOrdersPage([], null);
        }

        $rows = $this->reads->orders($stores, $filter, $after, self::PAGE + 1, $q, $customerId);
        $page = array_slice($rows, 0, self::PAGE);
        $customers = $this->reader->customers(array_map(static fn (array $row): string => (string) $row['customer_id'], $page));

        return new StaffOrdersPage(
            array_map(fn (array $row): StaffOrderRow => $this->reader->orderRow($row, $customers[(string) $row['customer_id']]), $page),
            count($rows) > self::PAGE && $page !== [] ? (string) $page[count($page) - 1]['id'] : null,
        );
    }
}
