<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOrders;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\Query\Staff\StaffOrderRow;
use Modules\Sales\Application\Query\Staff\StaffOrdersPage;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Service\OrderStatuses;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **Staff's order lists** (sales.md §3 ListOrders; handoff §14: Sales › All orders · Store orders · Unpaid
 * orders): one store's, or every store the reader covers — all, unpaid (holding less than is due), or flagged
 * short of stock — newest first, 50 a page, each with its four machines and its customer by name.
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

        $filter = in_array($query->filter, [StaffReads::ALL, StaffReads::UNPAID, StaffReads::SHORT], true) ? $query->filter : throw new InvalidSalesAttribute('filter', 'ALL, UNPAID or SHORT');

        if ($query->storeId !== null) {
            $store = Ulids::valid(strtolower(trim($query->storeId))) ? StoreId::fromString(strtolower(trim($query->storeId))) : throw new InvalidSalesAttribute('store', 'a store');
            $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
            $stores = [$store->value];
        } else {
            $this->authorizer->authorize(self::PERMISSION, $covered === null ? PermissionScope::allStores() : PermissionScope::store($covered[0]));
            $stores = $covered === null ? null : array_map(static fn (StoreId $store): string => $store->value, $covered);
        }

        $after = $query->after === null ? null : strtolower(trim($query->after));

        if ($after !== null && ! Ulids::valid($after)) {
            return new StaffOrdersPage([], null);
        }

        $rows = $this->reads->orders($stores, $filter, $after, self::PAGE + 1);
        $page = array_slice($rows, 0, self::PAGE);
        $customers = $this->reader->customers(array_map(static fn (array $row): string => (string) $row['customer_id'], $page));

        return new StaffOrdersPage(
            array_map(function (array $row) use ($customers): StaffOrderRow {
                $payment = OrderStatuses::payment((int) $row['due_minor'], (int) $row['paid_minor'], (int) $row['refunded_minor']);

                return new StaffOrderRow(
                    (string) $row['id'], (string) $row['number'], (string) $row['store_id'], CarbonImmutable::parse((string) $row['placed_at']),
                    $customers[(string) $row['customer_id']], (string) $row['status'], (string) $row['shipment_status'], $payment,
                    OrderStatuses::customer((string) $row['status'], (string) $row['shipment_status'], $payment),
                    Money::of((int) $row['order_total_minor'], (string) $row['currency_code']), $this->reader->exponent((string) $row['currency_code']),
                    $row['short_of_stock_at'] !== null, (bool) $row['may_ship_unpaid'],
                );
            }, $page),
            count($rows) > self::PAGE && $page !== [] ? (string) $page[count($page) - 1]['id'] : null,
        );
    }
}
