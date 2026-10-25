<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListReturns;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\Query\Staff\OrderStores;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReturnReads;
use Modules\Sales\Application\Query\Staff\StaffReturnRow;
use Modules\Sales\Application\Query\Staff\StaffReturnsPage;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Model\ReturnState;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **Staff's returns** (sales.md §3 ListReturns): one store's or every covered store's, one state or all, newest
 * first, 50 a page — each with its order's number and its customer by name. The stores those `OrderStores`
 * lists: an off one only while it holds an unfinished order, but to a Super Admin (§1.12).
 */
final readonly class ListReturnsHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    public const int PAGE = 50;

    private const array STATES = [ReturnState::REQUESTED, ReturnState::ACCEPTED, ReturnState::REFUSED, ReturnState::WITHDRAWN, ReturnState::RECEIVED, ReturnState::COMPLETED, ReturnState::CLOSED];

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private StaffReturnReads $reads,
        private StaffReader $reader,
        private OrderStores $stores,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(ListReturns $query): StaffReturnsPage
    {
        $covered = $this->authorizer->storesWith(self::PERMISSION);

        if ($this->actors->current()->type !== ActorType::Staff || $covered === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $status = $query->status === null ? null : (in_array(strtoupper($query->status), self::STATES, true) ? strtoupper($query->status) : throw new InvalidSalesAttribute('status', 'a return\'s state'));

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
            return new StaffReturnsPage([], null);
        }

        $rows = $this->reads->returns($stores, $status, $after, self::PAGE + 1);
        $page = array_slice($rows, 0, self::PAGE);
        $customers = $this->reader->customers(array_map(static fn (array $row): string => (string) $row['customer_id'], $page));

        return new StaffReturnsPage(
            array_map(fn (array $row): StaffReturnRow => new StaffReturnRow(
                (string) $row['id'], (string) $row['order_id'], (string) $row['number'], (string) $row['store_id'], $customers[(string) $row['customer_id']],
                (string) $row['status'], CarbonImmutable::parse((string) $row['requested_at']),
                $row['refund_due_minor'] === null ? null : Money::of((int) $row['refund_due_minor'], (string) $row['currency_code']),
                $this->reader->exponent((string) $row['currency_code']),
            ), $page),
            count($rows) > self::PAGE && $page !== [] ? (string) $page[count($page) - 1]['id'] : null,
        );
    }
}
