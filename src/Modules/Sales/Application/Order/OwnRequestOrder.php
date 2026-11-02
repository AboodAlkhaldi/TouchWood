<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use InvalidArgumentException;
use Modules\Sales\Application\Query\Own\OwnReader;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/** The customer's order behind a request's page (payments.md §1.5): only their own, in that store; anyone else's is no order. */
final readonly class OwnRequestOrder
{
    public function __construct(
        private OwnReader $reader,
        private OwnReads $reads,
    ) {}

    /**
     * @return array{string, StoreId, string, int} the order, its store, the customer, and what it is missing as read
     *
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound
     */
    public function of(string $storeId, string $number): array
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSalesAttribute('store', 'not a store id');
        }

        $customerId = $this->reader->authorize();
        $number = OwnReader::number($number) ?? throw new OrderNotFound;
        $order = $this->reads->order($customerId, $number);

        if ($order === null || $order['store_id'] !== $store->value) {
            throw new OrderNotFound;
        }

        return [(string) $order['id'], $store, $customerId, (int) $order['due_minor'] - (int) $order['paid_minor'] + (int) $order['refunded_minor']];
    }
}
