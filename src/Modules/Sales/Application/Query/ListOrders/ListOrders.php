<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOrders;

final readonly class ListOrders
{
    public function __construct(
        /** One store (Store orders); null every store the reader covers (All orders). */
        public ?string $storeId = null,
        /** ALL, UNPAID, SHORT, WAITING_APPROVAL, TO_SHIP, SHIPPED or TRANSFERS_TO_CHECK. */
        public string $filter = 'ALL',
        /** The id of the last order shown. */
        public ?string $after = null,
        /** One box: an order number, a customer's name or a phone — 2 to 100 characters; shorter, no search. */
        public ?string $q = null,
        /** One customer's orders only (the customer's page: "See all of this customer's orders"). */
        public ?string $customerId = null,
    ) {}
}
