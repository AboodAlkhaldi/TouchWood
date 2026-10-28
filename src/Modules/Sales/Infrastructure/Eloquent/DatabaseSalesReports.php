<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\MoneyByWayDto;
use Modules\Sales\Public\Dto\MoneyDaysDto;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\OrderMessageLineDto;
use Modules\Sales\Public\Dto\ReturnMessageDto;
use Modules\Sales\Public\Dto\SalesCountsDto;
use Modules\Sales\Public\Dto\TransfersWaitingDto;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/** `SalesReports` in SQL (sales.md §2.5): Ops' reads of orders, returns and money. */
final readonly class DatabaseSalesReports implements SalesReports
{
    public function __construct(
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    public function orderForMessage(string $orderId, string $locale): ?OrderMessageDto
    {
        $order = Ulids::valid($orderId) ? $this->db->table('sales.orders')->where('id', strtolower($orderId))->first() : null;

        if (! $order instanceof stdClass) {
            return null;
        }

        $arabic = $locale === 'ar';
        $money = static fn (mixed $minor): Money => Money::of((int) $minor, (string) $order->currency_code);
        $lines = $this->db->table('sales.order_lines')->where('order_id', $order->id)->orderBy('is_gift')->orderBy('id')
            ->get(['name_ar', 'name_en', 'variant_values', 'quantity', 'total_minor', 'is_gift'])->all();

        return new OrderMessageDto(
            (string) $order->id, (string) $order->number, (string) $order->store_id, (string) $order->customer_id, (string) $order->locale,
            (string) $order->status, (string) $order->shipment_status, (string) $order->way_to_pay,
            array_values(array_map(static function (stdClass $line) use ($arabic, $money): OrderMessageLineDto {
                /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
                $values = json_decode((string) $line->variant_values, true, 512, JSON_THROW_ON_ERROR);

                return new OrderMessageLineDto(
                    $arabic ? (string) $line->name_ar : (string) $line->name_en,
                    array_map(static fn (array $value): array => [
                        'attribute' => $arabic ? $value['attributeAr'] : $value['attributeEn'],
                        'value' => $arabic ? $value['valueAr'] : $value['valueEn'],
                    ], $values),
                    (int) $line->quantity, $money($line->total_minor), (bool) $line->is_gift,
                );
            }, $lines)),
            $money($order->net_subtotal_minor), $money($order->coupon_discount_minor), $money($order->points_discount_minor),
            $money($order->shipping_fee_minor), $money($order->vat_minor), $money($order->order_total_minor),
            $order->carrier === null ? null : (string) $order->carrier,
            $order->tracking_number === null ? null : (string) $order->tracking_number,
            $order->status === 'REJECTED' && $order->decision_reason !== null ? (string) $order->decision_reason : null,
            $order->cancel_reason === null ? null : (string) $order->cancel_reason,
        );
    }

    public function returnForMessage(string $returnId): ?ReturnMessageDto
    {
        $row = Ulids::valid($returnId) ? $this->db->table('sales.returns as r')->join('sales.orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.id', strtolower($returnId))
            ->first(['r.id', 'r.order_id', 'o.number', 'r.store_id', 'o.customer_id', 'r.status', 'r.staff_reason', 'r.refund_due_minor', 'o.currency_code']) : null;

        return $row instanceof stdClass ? new ReturnMessageDto(
            (string) $row->id, (string) $row->order_id, (string) $row->number, (string) $row->store_id, (string) $row->customer_id, (string) $row->status,
            $row->status === 'REFUSED' && $row->staff_reason !== null ? (string) $row->staff_reason : null,
            $row->refund_due_minor === null ? null : Money::of((int) $row->refund_due_minor, (string) $row->currency_code),
        ) : null;
    }

    public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): SalesCountsDto
    {
        if ($from >= $to) {
            return new SalesCountsDto(0, 0, 0, 0, 0, 0);
        }

        [$f, $t] = [Moment::of($from), Moment::of($to)];
        $in = static fn (string $column): string => "({$column} >= ? AND {$column} < ?)";
        $row = $this->db->selectOne(
            'SELECT count(*) FILTER (WHERE '.$in('placed_at').') AS placed,'
            .' count(*) FILTER (WHERE '.$in('placed_at')." AND audience = 'PUBLIC') AS individuals,"
            .' count(*) FILTER (WHERE '.$in('placed_at')." AND audience = 'COMPANY') AS companies,"
            ." count(*) FILTER (WHERE status = 'CANCELLED' AND ".$in('cancelled_at').') AS cancelled,'
            ." count(*) FILTER (WHERE status = 'REJECTED' AND ".$in('decided_at').') AS rejected,'
            .' count(*) FILTER (WHERE '.$in('came_back_at').') AS came_back'
            .' FROM sales.orders WHERE store_id = ? AND ('.$in('placed_at').' OR '.$in('cancelled_at').' OR '.$in('decided_at').' OR '.$in('came_back_at').')',
            [$f, $t, $f, $t, $f, $t, $f, $t, $f, $t, $f, $t, $store->value, $f, $t, $f, $t, $f, $t, $f, $t],
        );

        return $row instanceof stdClass
            ? new SalesCountsDto((int) $row->placed, (int) $row->individuals, (int) $row->companies, (int) $row->cancelled, (int) $row->rejected, (int) $row->came_back)
            : new SalesCountsDto(0, 0, 0, 0, 0, 0);
    }

    public function moneyOn(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): MoneyDaysDto
    {
        $read = fn (string $table): array => array_values(array_map(
            static fn (stdClass $row): MoneyByWayDto => new MoneyByWayDto((string) $row->way, (int) $row->records, Money::of((int) $row->amount, (string) $row->currency_code)),
            $this->db->table($table)
                ->where('store_id', $store->value)->whereNull('voided_at')
                ->where('happened_on', '>=', $fromDay->format('Y-m-d'))->where('happened_on', '<=', $toDay->format('Y-m-d'))
                ->groupBy('way', 'currency_code')->orderBy('way')
                ->get(['way', 'currency_code', $this->db->raw('count(*) AS records'), $this->db->raw('sum(amount_minor) AS amount')])->all(),
        ));

        return new MoneyDaysDto($read('sales.payments'), $read('sales.refunds'));
    }

    public function transfersWaiting(StoreId $store): TransfersWaitingDto
    {
        $row = $this->db->table('sales.orders')
            ->where('store_id', $store->value)->where('way_to_pay', 'BANK_TRANSFER')
            ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED', 'COMPLETED'])
            ->whereRaw('due_minor > paid_minor - refunded_minor')
            ->first([$this->db->raw('count(*) AS orders'), $this->db->raw('coalesce(sum(due_minor - (paid_minor - refunded_minor)), 0) AS owed')]);
        $currency = $this->platform->store($store)->currencyCode ?? throw new InvalidArgumentException('No such store.');

        return $row instanceof stdClass
            ? new TransfersWaitingDto((int) $row->orders, Money::of((int) $row->owed, $currency))
            : new TransfersWaitingDto(0, Money::zero($currency));
    }
}
