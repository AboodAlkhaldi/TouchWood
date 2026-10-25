<?php

declare(strict_types=1);

use Modules\Sales\Domain\Service\OrderStatuses;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;

/*
| The statuses worked out of an order's machines (sales.md §1.4): the payment from what is due against what it
| holds; the one status its customer sees.
*/

it('works the payment out of what is due and what is held', function (int $due, int $paid, int $refunded, PaymentStatus $status) {
    expect(OrderStatuses::payment($due, $paid, $refunded))->toBe($status);
})->with([
    'nothing due, nothing paid' => [0, 0, 0, PaymentStatus::NothingDue],
    'due, nothing held' => [100_00, 0, 0, PaymentStatus::Unpaid],
    'due, all paid back' => [100_00, 50_00, 50_00, PaymentStatus::Unpaid],
    'part held' => [100_00, 40_00, 0, PaymentStatus::PartlyPaid],
    'all held' => [100_00, 100_00, 0, PaymentStatus::Paid],
    'more held than due' => [60_00, 100_00, 0, PaymentStatus::RefundDue],
    'cancelled, still held' => [0, 100_00, 0, PaymentStatus::RefundDue],
    'cancelled, paid back' => [0, 100_00, 100_00, PaymentStatus::Refunded],
]);

it('shows the customer one status', function (string $status, string $shipment, PaymentStatus $payment, CustomerOrderStatus $seen) {
    expect(OrderStatuses::customer($status, $shipment, $payment))->toBe($seen);
})->with([
    'waiting' => ['PENDING_APPROVAL', 'NOT_SHIPPED', PaymentStatus::Unpaid, CustomerOrderStatus::New],
    'approved' => ['APPROVED', 'NOT_SHIPPED', PaymentStatus::Paid, CustomerOrderStatus::Processing],
    'shipped' => ['APPROVED', 'SHIPPED', PaymentStatus::Paid, CustomerOrderStatus::Shipped],
    'delivered' => ['COMPLETED', 'DELIVERED', PaymentStatus::Paid, CustomerOrderStatus::Delivered],
    'delivered, partly returned and owed' => ['COMPLETED', 'DELIVERED', PaymentStatus::RefundDue, CustomerOrderStatus::Delivered],
    'cancelled' => ['CANCELLED', 'NOT_SHIPPED', PaymentStatus::NothingDue, CustomerOrderStatus::Cancelled],
    'rejected' => ['REJECTED', 'NOT_SHIPPED', PaymentStatus::NothingDue, CustomerOrderStatus::Cancelled],
    'came back' => ['UNDELIVERED', 'CAME_BACK', PaymentStatus::RefundDue, CustomerOrderStatus::Cancelled],
    'cancelled and paid back' => ['CANCELLED', 'NOT_SHIPPED', PaymentStatus::Refunded, CustomerOrderStatus::Refunded],
    'wholly returned and paid back' => ['COMPLETED', 'DELIVERED', PaymentStatus::Refunded, CustomerOrderStatus::Refunded],
]);
