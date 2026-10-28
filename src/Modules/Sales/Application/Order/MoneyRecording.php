<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\RefundTooLarge;
use Modules\Sales\Domain\Model\MoneyRecord;
use Modules\Sales\Domain\Repository\MoneyRecords;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\PaymentRecorded;
use Modules\Sales\Public\Events\RefundRecorded;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * A payment or a refund staff record by hand (sales.md §1.9): **a payment is always the whole balance
 * still owed** — due less what is held, filled in by Sales, never typed (owner, 2026-10-27: no partial
 * payments) — refused when nothing is owed; a refund's amount is typed, in the order's own currency; the way — a bank transfer, or "other" with a note (online is stage 7's, through Payments) — a
 * reference, the day the money moved (not after today in the store's own time), and the form's request
 * id: **pressing twice records once**. What staff type — the reference, the note — is audited as written
 * only. A refund never above what the order holds (paid less refunded). Under the order's lock;
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
        private Outbox $outbox,
    ) {}

    /**
     * The order and the staff member as `OrderAccess::authorize` answered them, under `PERMISSION`. A
     * payment's amount is the balance owed under the order's lock (`$amount` null); a refund's is given.
     *
     * @return string the record's id — the one already made, for a form sent twice
     *
     * @throws InvalidSalesAttribute|InvalidOrderChange|OrderNotFound|RefundTooLarge
     */
    public function record(string $kind, string $orderId, string $staffId, ?string $amount, string $way, ?string $reference, ?string $note, string $happenedOn, string $requestId, ?string $shown = null): string
    {
        $way = strtoupper(trim($way));
        $reference = self::optional($reference, 100, 'reference');
        $note = self::optional($note, 500, 'note');
        $request = Str::isUuid(trim($requestId)) ? strtolower(trim($requestId)) : throw new InvalidSalesAttribute('request', 'a form id');

        if (! in_array($way, self::WAYS, true)) {
            throw new InvalidSalesAttribute('way', 'a bank transfer or other');
        }

        if ($way === 'OTHER' && $note === null) {
            throw new InvalidSalesAttribute('note', 'what the other way was');
        }

        return $this->db->transaction(function () use ($kind, $orderId, $staffId, $amount, $way, $reference, $note, $happenedOn, $request, $shown): string {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;
            $made = $this->records->byRequest($kind, $request);

            if ($made !== null) {
                return $made->orderId === $order->id ? $made->id : throw new InvalidSalesAttribute('request', 'a form of another order');
            }

            $currency = $this->platform->currency($order->currencyCode) ?? throw new LogicException("The order's currency {$order->currencyCode} is not known.");
            $day = self::day($happenedOn, $this->platform->store(StoreId::fromString($order->storeId))->timezone ?? 'UTC');
            // A payment: the whole balance still owed, worked out under the lock; nothing owed, nothing to record.
            $money = $kind === MoneyRecord::PAYMENT
                ? ($order->due - $order->held() > 0 ? Money::of($order->due - $order->held(), $order->currencyCode) : throw new InvalidOrderChange)
                : self::amount($amount ?? '', $order->currencyCode, $currency->exponent);

            // The balance the screen showed, when it says: an edit or a refund meanwhile changed it - shown again,
            // never recorded against a transfer of another amount.
            if ($shown !== null && ! self::amount($shown, $order->currencyCode, $currency->exponent)->equals($money)) {
                throw new InvalidOrderChange;
            }

            if ($kind === MoneyRecord::REFUND && $money->minorUnits > $order->held()) {
                throw new RefundTooLarge;
            }

            $now = CarbonImmutable::now();
            $id = strtolower((string) Str::ulid());
            try {
                $this->records->add(new MoneyRecord($kind, $id, $order->id, $order->storeId, $order->currencyCode, $money->minorUnits, $way, $reference, $note, $day, $request, $staffId, $now));
            } catch (QueryException $taken) {
                // The same form id sent for another order at the same moment: the unique index decided.
                throw $taken->getCode() === '23505' && str_contains($taken->getMessage(), '_request') ? new InvalidSalesAttribute('request', 'a form of another order') : $taken;
            }
            $kind === MoneyRecord::PAYMENT ? $this->orders->moveMoney($order->id, $money->minorUnits, 0) : $this->orders->moveMoney($order->id, 0, $money->minorUnits);

            $this->outbox->record($kind === MoneyRecord::PAYMENT
                ? new PaymentRecorded((string) Str::uuid(), $id, $order->id, $order->storeId, $order->customerId, $now)
                : new RefundRecorded((string) Str::uuid(), $id, $order->id, $order->storeId, $order->customerId, $now));

            $historyKind = $kind === MoneyRecord::PAYMENT ? 'PAYMENT_RECORDED' : 'REFUND_RECORDED';
            $this->orders->recordHistory($order->id, $order->storeId, $historyKind, null, $staffId, $now, ['recordId' => $id, 'amount' => $money->minorUnits, 'way' => $way]);
            $this->platform->recordAudit(new AuditEntryDto('sales.'.strtolower($kind).'.recorded', 'sales.'.strtolower($kind), $id, $order->storeId, AuditChanges::none()
                ->changed('order_id', null, $order->id)->changed('amount_minor', null, $money->minorUnits)->changed('way', null, $way)
                ->changed('happened_on', null, $day->format('Y-m-d'))->personal('reference')->personal('note')));

            return $id;
        }, 3);
    }

    /** The most one record may hold, in the smallest unit — far above any order, far below what a column holds. */
    public const int MOST = 1_000_000_000_000;

    /** @throws InvalidSalesAttribute */
    private static function amount(string $amount, string $currency, int $exponent): Money
    {
        try {
            $money = Money::fromDecimal(trim($amount), $currency, $exponent);
        } catch (MoneyException|MathException) {
            throw new InvalidSalesAttribute('amount', "an amount with at most {$exponent} decimals");
        }

        return $money->isPositive() && $money->minorUnits <= self::MOST ? $money : throw new InvalidSalesAttribute('amount', 'above 0, within reason');
    }

    /**
     * The day the money moved, not after today in the store's own time.
     *
     * @throws InvalidSalesAttribute
     */
    private static function day(string $day, string $timezone): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($day)) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', trim($day)) : null;

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== trim($day) || $parsed->format('Y-m-d') > CarbonImmutable::now($timezone)->format('Y-m-d')) {
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
