<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewOwnOrder;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Sales\Application\Query\Own\OwnOrderView;
use Modules\Sales\Application\Query\Own\OwnReader;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Application\Query\Own\OwnReturnRow;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Returns;
use Modules\Sales\Domain\Service\OrderStatuses;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **The customer's own order** (sales.md §3 ViewOwnOrder), by its number: its lines as sold, its totals, the
 * status they see and the payment's, its shipment, its address, its returns and documents — and what they may
 * still do: **cancel** while it waits or is approved and unshipped (§1.6), **ask a return** within the window
 * while a piece is left to ask (§1.8, each line's pieces given), **upload a transfer document** while paid by
 * bank transfer it holds less than is due (§1.9). Another customer's number is no order.
 */
final readonly class ViewOwnOrderHandler
{
    public function __construct(
        private OwnReader $reader,
        private OwnReads $reads,
        private Returns $returnsRepository,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound
     */
    public function handle(ViewOwnOrder $query): OwnOrderView
    {
        $customerId = $this->reader->authorize();
        $number = OwnReader::number($query->number) ?? throw new OrderNotFound;
        $order = $this->reads->order($customerId, $number) ?? throw new OrderNotFound;
        $id = (string) $order['id'];
        $currency = (string) $order['currency_code'];
        $money = static fn (mixed $minor): Money => Money::of((int) $minor, $currency);
        $at = static fn (mixed $moment): ?CarbonImmutable => $moment === null ? null : CarbonImmutable::parse((string) $moment);

        $deadline = $at($order['return_deadline_at']);
        // In whole seconds, as RequestReturn reads the window.
        $inWindow = $order['status'] === OrderState::COMPLETED && $deadline !== null && CarbonImmutable::now()->startOfSecond() <= $deadline;
        $counted = $inWindow ? $this->returnsRepository->returnable($id) : [];
        $lines = array_map(static function (array $line) use ($money, $counted): OrderLineView {
            /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
            $values = json_decode((string) $line['variant_values'], true, 512, JSON_THROW_ON_ERROR);
            $held = $counted[(string) $line['id']] ?? null;

            return new OrderLineView(
                (string) $line['id'], (string) $line['product_id'], (string) $line['variant_id'], (string) $line['name_ar'], (string) $line['name_en'],
                $values, (string) $line['code'], (string) $line['sale_mode'], (bool) $line['is_gift'], (int) $line['quantity'],
                $money($line['unit_minor']), $money($line['list_unit_minor']), $money($line['total_minor']),
                $held === null ? 0 : max(0, $held['quantity'] - $held['counted']),
            );
        }, $this->reads->lines($id));

        $payment = OrderStatuses::payment((int) $order['due_minor'], (int) $order['paid_minor'], (int) $order['refunded_minor']);
        $documents = $this->reads->transferDocuments($id);
        /** @var array<string, mixed> $address */
        $address = json_decode((string) $order['address'], true, 512, JSON_THROW_ON_ERROR);

        return new OwnOrderView(
            (string) $order['number'], (string) $order['store_id'], CarbonImmutable::parse((string) $order['placed_at']),
            OrderStatuses::customer((string) $order['status'], (string) $order['shipment_status'], $payment), $payment, (string) $order['way_to_pay'],
            $lines, $money($order['gross_subtotal_minor']), $money($order['net_subtotal_minor']),
            $order['coupon_code'] === null ? null : (string) $order['coupon_code'], $money($order['coupon_discount_minor']),
            (int) $order['points_used'], $money($order['points_discount_minor']), $money($order['goods_total_minor']), $money($order['shipping_fee_minor']),
            $money($order['vat_minor']), $money($order['order_total_minor']), $money($order['due_minor']), $money($order['paid_minor']), $money($order['refunded_minor']),
            (string) $order['shipment_status'], $order['carrier'] === null ? null : (string) $order['carrier'],
            $order['tracking_number'] === null ? null : (string) $order['tracking_number'], $at($order['shipped_at']), $at($order['delivered_at']), $deadline,
            $address,
            array_map(static fn (array $return): OwnReturnRow => new OwnReturnRow(
                (string) $return['id'], (string) $return['status'], CarbonImmutable::parse((string) $return['requested_at']),
                $return['refund_due_minor'] === null ? null : $money($return['refund_due_minor']),
            ), $this->reads->returns($id)),
            $documents,
            in_array($order['status'], [OrderState::PENDING_APPROVAL, OrderState::APPROVED], true) && $order['shipment_status'] === OrderState::NOT_SHIPPED,
            $inWindow && array_filter($lines, static fn (OrderLineView $line): bool => $line->returnable > 0) !== [],
            $order['way_to_pay'] === WayToPay::BankTransfer->value && (int) $order['paid_minor'] - (int) $order['refunded_minor'] < (int) $order['due_minor']
                && $documents < UploadTransferDocumentHandler::MOST,
        );
    }
}
