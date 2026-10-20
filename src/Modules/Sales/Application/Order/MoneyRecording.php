<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\RefundTooLarge;
use Modules\Sales\Domain\Model\MoneyRecord;
use Modules\Sales\Domain\Repository\MoneyRecords;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;

/**
 * A payment or a refund staff record by hand (sales.md §1.9): the amount in the order's own currency,
 * the way — a bank transfer, or "other" with a note (online is stage 7's, through Payments) — a
 * reference, the day the money moved (not after today), and the form's request id: **pressing twice
 * records once**. A refund never above what the order holds (paid less refunded). Under the order's lock;
 * in its history; audited.
 */
final readonly class MoneyRecording
{
    public const string PERMISSION = SalesPermissions::PAYMENT_RECORD;

    /** The ways staff record by hand; `ONLINE` is Payments' own (stage 7). */
    public const array WAYS = ['BANK_TRANSFER', 'OTHER'];

    public function __construct(
        private Orders $orders,
        private MoneyRecords $records,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * The order and the staff member as `OrderAccess::authorize` answered them, under `PERMISSION`.
     *
     * @return string the record's id — the one already made, for a form sent twice
     *
     * @throws InvalidSalesAttribute|OrderNotFound|RefundTooLarge
     */
    public function record(string $kind, string $orderId, string $staffId, string $amount, string $way, ?string $reference, ?string $note, string $happenedOn, string $requestId): string
    {
        $way = strtoupper(trim($way));
        $reference = self::optional($reference, 100, 'reference');
        $note = self::optional($note, 500, 'note');
        $day = self::day($happenedOn);
        $request = Str::isUuid(trim($requestId)) ? strtolower(trim($requestId)) : throw new InvalidSalesAttribute('request', 'a form id');

        if (! in_array($way, self::WAYS, true)) {
            throw new InvalidSalesAttribute('way', 'a bank transfer or other');
        }

        if ($way === 'OTHER' && $note === null) {
            throw new InvalidSalesAttribute('note', 'what the other way was');
        }

        return $this->db->transaction(function () use ($kind, $orderId, $staffId, $amount, $way, $reference, $note, $day, $request): string {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;
            $made = $this->records->byRequest($kind, $request);

            if ($made !== null) {
                return $made->orderId === $order->id ? $made->id : throw new InvalidSalesAttribute('request', 'a form of another order');
            }

            $money = self::amount($amount, $order->currencyCode, $this->platform->currency($order->currencyCode)->exponent ?? 2);

            if ($kind === MoneyRecord::REFUND && $money->minorUnits > $order->held()) {
                throw new RefundTooLarge;
            }

            $now = CarbonImmutable::now();
            $id = strtolower((string) Str::ulid());
            $this->records->add(new MoneyRecord($kind, $id, $order->id, $order->storeId, $order->currencyCode, $money->minorUnits, $way, $reference, $note, $day, $request, $staffId, $now));
            $kind === MoneyRecord::PAYMENT ? $this->orders->moveMoney($order->id, $money->minorUnits, 0) : $this->orders->moveMoney($order->id, 0, $money->minorUnits);

            $historyKind = $kind === MoneyRecord::PAYMENT ? 'PAYMENT_RECORDED' : 'REFUND_RECORDED';
            $this->orders->recordHistory($order->id, $order->storeId, $historyKind, null, $staffId, $now, ['recordId' => $id, 'amount' => $money->minorUnits, 'way' => $way]);
            $this->platform->recordAudit(new AuditEntryDto('sales.'.strtolower($kind).'.recorded', 'sales.'.strtolower($kind), $id, $order->storeId, AuditChanges::none()
                ->changed('order_id', null, $order->id)->changed('amount_minor', null, $money->minorUnits)->changed('way', null, $way)
                ->changed('reference', null, $reference)->changed('happened_on', null, $day->format('Y-m-d'))->personal('note')));

            return $id;
        }, 3);
    }

    /** @throws InvalidSalesAttribute */
    private static function amount(string $amount, string $currency, int $exponent): Money
    {
        try {
            $money = Money::fromDecimal(trim($amount), $currency, $exponent);
        } catch (MoneyException|MathException) {
            throw new InvalidSalesAttribute('amount', "an amount with at most {$exponent} decimals");
        }

        return $money->isPositive() ? $money : throw new InvalidSalesAttribute('amount', 'above 0');
    }

    /** @throws InvalidSalesAttribute */
    private static function day(string $day): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($day)) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', trim($day)) : null;

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== trim($day) || $parsed->isAfter(CarbonImmutable::now()->endOfDay())) {
            throw new InvalidSalesAttribute('date', 'a day, not after today');
        }

        return $parsed;
    }

    /** @throws InvalidSalesAttribute */
    private static function optional(?string $text, int $most, string $field): ?string
    {
        $value = $text === null ? '' : trim($text);

        if (mb_strlen($value) > $most) {
            throw new InvalidSalesAttribute($field, "at most {$most} characters");
        }

        return $value === '' ? null : $value;
    }
}
