<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PrintOrder;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Query\Own\OrderLines;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/** **An order to print** (sales.md §3 PrintOrder): its facts for a packing slip or an invoice, by staff who view orders. */
final readonly class PrintOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    public function __construct(
        private OrderAccess $access,
        private StaffReads $reads,
        private OrderLines $lines,
        private StaffReader $reader,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound
     */
    public function handle(PrintOrder $query): PrintOrderView
    {
        [$orderId] = $this->access->authorize(self::PERMISSION, $query->orderId);
        $order = $this->reads->order($orderId) ?? throw new OrderNotFound;
        $currency = (string) $order['currency_code'];
        $money = static fn (mixed $minor): Money => Money::of((int) $minor, $currency);
        [$lines] = $this->lines->of($order);
        /** @var array<string, mixed> $address */
        $address = json_decode((string) $order['address'], true, 512, JSON_THROW_ON_ERROR);

        return new PrintOrderView(
            (string) $order['store_id'], (string) $order['number'], CarbonImmutable::parse((string) $order['placed_at']),
            (string) $order['customer_name'], (string) $order['customer_phone'], $address, $lines,
            $money($order['gross_subtotal_minor']), $money($order['net_subtotal_minor']), $money($order['coupon_discount_minor']),
            $money($order['points_discount_minor']), $money($order['goods_total_minor']), $money($order['shipping_fee_minor']),
            $money($order['vat_minor']), (int) $order['vat_rate_basis_points'], $money($order['order_total_minor']), WayToPay::from((string) $order['way_to_pay']),
            $this->reader->exponent($currency),
        );
    }
}
