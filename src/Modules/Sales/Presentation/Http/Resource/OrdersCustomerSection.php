<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use DateTimeZone;
use Modules\Access\Public\Contracts\CustomerPageSection;
use Modules\Sales\Application\Query\ListCustomerOrders\ListCustomerOrders;
use Modules\Sales\Application\Query\ListCustomerOrders\ListCustomerOrdersHandler;
use Modules\Sales\Application\Query\Staff\StaffOrderRow;
use Shared\Application\Unauthorized;

/**
 * The customer's latest orders on the staff customer page (sales.md §3.2; access.md amendment 72(a)):
 * no section at all for a reader who views no orders; an empty list for a customer with none they may see.
 */
final readonly class OrdersCustomerSection implements CustomerPageSection
{
    public function __construct(
        private ListCustomerOrdersHandler $handler,
    ) {}

    public function sectionFor(string $customerId): ?array
    {
        try {
            $orders = $this->handler->handle(new ListCustomerOrders($customerId));
        } catch (Unauthorized) {
            return null;
        }

        return (new CustomerOrdersSectionData(array_map(static fn (StaffOrderRow $row): CustomerOrderData => new CustomerOrderData(
            $row->orderId, $row->number, $row->storeId, $row->placedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP'),
            $row->status, $row->shipment, $row->payment->value, $row->customerStatus->value, new MoneyData($row->total->toDecimal($row->exponent), $row->total->currencyCode),
            $row->shortOfStock, $row->mayShipUnpaid,
        ), $orders)))->toArray();
    }
}
