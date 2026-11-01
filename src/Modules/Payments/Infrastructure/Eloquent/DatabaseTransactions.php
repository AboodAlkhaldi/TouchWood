<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\TransactionFacts;
use Modules\Payments\Domain\Repository\Transactions;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Shared\Domain\ValueObject\Money;
use stdClass;

final readonly class DatabaseTransactions implements Transactions
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function succeeded(PaymentMode $mode, string $gatewayPaymentId): ?bool
    {
        $value = $this->db->table('payments.transactions')->where('gateway', 'MYFATOORAH')->where('mode', $mode->value)->where('gateway_payment_id', $gatewayPaymentId)->value('succeeded');

        return $value === null ? null : (bool) $value;
    }

    public function record(Attempt $attempt, TransactionFacts $facts): string
    {
        $row = [
            'attempt_id' => $facts->attemptId,
            'status' => $facts->status,
            'succeeded' => $facts->succeeded,
            'method' => $facts->method,
            'card_brand' => $facts->cardBrand,
            'amount_minor' => $facts->amountMinor,
            'amount_text' => $facts->amountText,
            'currency_code' => $facts->currencyCode,
            'base_currency_code' => $facts->baseCurrencyCode,
            'paid_at' => $facts->paidAt,
            'error_code' => $facts->errorCode,
            'error_message' => $facts->errorMessage,
            'mismatch' => $facts->mismatch,
            'paid_twice' => $facts->paidTwice,
            'published' => $facts->published,
            'seen_at' => CarbonImmutable::now(),
        ];
        $keys = ['gateway' => 'MYFATOORAH', 'mode' => $attempt->mode->value, 'gateway_payment_id' => $facts->gatewayPaymentId];
        $existing = $this->db->table('payments.transactions')->where($keys)->value('id');

        // A declined one that later succeeded: one row, and success wins (rule 5).
        if ($existing !== null) {
            $this->db->table('payments.transactions')->where('id', $existing)->update($row);

            return (string) $existing;
        }

        return (string) $this->db->table('payments.transactions')->insertGetId([...$keys, ...$row]);
    }

    public function dto(string $id): ?PaymentDto
    {
        if (preg_match('/\\A[1-9][0-9]{0,18}\\z/', $id) !== 1) {
            return null;
        }

        $row = $this->db->table('payments.transactions as t')
            ->join('payments.attempts as a', 'a.id', '=', 't.attempt_id')
            ->where('t.id', $id)
            ->select(['t.*', 'a.owner_id', 'a.owner_kind', 'a.order_id', 'a.amount_minor as expected_minor', 'a.currency_code as expected_currency'])
            ->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $currency = (string) $row->expected_currency;
        $expected = Money::of((int) $row->expected_minor, $currency);

        return new PaymentDto(
            (string) $row->id,
            (string) $row->attempt_id,
            (string) $row->owner_id,
            PaymentOwnerKind::from((string) $row->owner_kind),
            (string) $row->order_id,
            $row->amount_minor === null ? $expected : Money::of((int) $row->amount_minor, $currency),
            $expected,
            (string) $row->method,
            (string) $row->gateway_payment_id,
            PaymentMode::from((string) $row->mode),
            (bool) $row->succeeded,
            self::moment($row->paid_at),
            $row->error_code === null ? null : (string) $row->error_code,
            (bool) $row->mismatch,
            (bool) $row->paid_twice,
        );
    }

    private static function moment(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : (new CarbonImmutable((string) $value))->toDateTimeImmutable();
    }
}
