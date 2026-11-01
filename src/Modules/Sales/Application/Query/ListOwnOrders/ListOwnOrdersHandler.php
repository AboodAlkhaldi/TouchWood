<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOwnOrders;

use Modules\Sales\Application\Query\OrderShippingView;
use Modules\Sales\Application\Query\Own\OwnOrderRow;
use Modules\Sales\Application\Query\Own\OwnOrdersPage;
use Modules\Sales\Application\Query\Own\OwnReader;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Domain\Service\OrderStatuses;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **The customer's orders** (sales.md §3 ListOwnOrders): every store's, newest first, 20 a page — each by its
 * number, its store, when placed, the one status they see, its total and its pieces. Show More goes on after
 * the last number; a number not theirs ends the list.
 */
final readonly class ListOwnOrdersHandler
{
    /** Orders a page. */
    public const int PAGE = 20;

    public function __construct(
        private OwnReader $reader,
        private OwnReads $reads,
    ) {}

    /** @throws Unauthorized */
    public function handle(ListOwnOrders $query): OwnOrdersPage
    {
        $customerId = $this->reader->authorize();
        $after = $query->after === null ? null : OwnReader::number($query->after);

        if ($query->after !== null && $after === null) {
            return new OwnOrdersPage([], null);
        }

        $rows = $this->reads->orders($customerId, $after, self::PAGE + 1);
        $page = array_slice($rows, 0, self::PAGE);

        return new OwnOrdersPage(
            array_map(fn (array $row): OwnOrderRow => new OwnOrderRow(
                $row['number'], $row['storeId'], $row['placedAt'],
                OrderStatuses::customer($row['status'], $row['shipment'], OrderStatuses::payment($row['due'], $row['paid'], $row['refunded'])),
                Money::of($row['total'], $row['currency']), $row['pieces'], $this->reader->exponent($row['currency']),
                OrderShippingView::feeOpen(['shipping_fee_pending' => $row['shippingPending'], 'status' => $row['status']]),
            ), $page),
            count($rows) > self::PAGE && $page !== [] ? $page[count($page) - 1]['number'] : null,
        );
    }
}
