<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Service;

use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;

/**
 * The statuses worked out of an order's machines (sales.md §1.4) — the payment from what is due and held, and
 * the one status its customer sees.
 */
final class OrderStatuses
{
    /**
     * @param  int  $due  what is due now: 0 for a cancelled, rejected or undelivered order, otherwise the total less the refunds owed for completed returns
     */
    public static function payment(int $due, int $paid, int $refunded): PaymentStatus
    {
        $held = $paid - $refunded;

        if ($due === 0 && $held === 0) {
            return $paid > 0 ? PaymentStatus::Refunded : PaymentStatus::NothingDue;
        }

        return match (true) {
            $held > $due => PaymentStatus::RefundDue,
            $held === $due => PaymentStatus::Paid,
            $held <= 0 => PaymentStatus::Unpaid,
            default => PaymentStatus::PartlyPaid,
        };
    }

    /**
     * New while waiting; Processing once approved; Shipped; Delivered; Cancelled when cancelled, rejected or
     * undelivered; Refunded once such an order — or a wholly returned one — has its money back.
     */
    public static function customer(string $status, string $shipment, PaymentStatus $payment): CustomerOrderStatus
    {
        if ($payment === PaymentStatus::Refunded) {
            return CustomerOrderStatus::Refunded;
        }

        return match (true) {
            in_array($status, [OrderState::CANCELLED, OrderState::REJECTED, OrderState::UNDELIVERED], true) => CustomerOrderStatus::Cancelled,
            $status === OrderState::PENDING_APPROVAL => CustomerOrderStatus::New,
            $shipment === 'DELIVERED' => CustomerOrderStatus::Delivered,
            $shipment === OrderState::SHIPPED => CustomerOrderStatus::Shipped,
            default => CustomerOrderStatus::Processing,
        };
    }
}
