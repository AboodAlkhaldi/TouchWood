<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Model\PaymentRequest;
use Modules\Payments\Domain\Repository\PaymentRequests;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentRequestState;
use stdClass;

final readonly class DatabasePaymentRequests implements PaymentRequests
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(PaymentRequest $request): bool
    {
        return $this->db->table('payments.requests')->insertOrIgnore([
            'id' => $request->id,
            'store_id' => $request->storeId,
            'order_id' => $request->orderId,
            'order_number' => $request->orderNumber,
            'customer_id' => $request->customerId,
            'amount_minor' => $request->amountMinor,
            'currency_code' => $request->currencyCode,
            'mode' => $request->mode->value,
            'state' => $request->state->value,
            'expires_at' => $request->expiresAt,
            'requested_by' => $request->requestedBy,
            'created_at' => $request->createdAt,
            'updated_at' => $request->createdAt,
        ]) === 1;
    }

    public function lock(string $id): ?PaymentRequest
    {
        return self::request($this->db->table('payments.requests')->where('id', strtolower($id))->lockForUpdate()->first());
    }

    public function openOf(string $orderId): ?PaymentRequest
    {
        return self::request($this->db->table('payments.requests')->where('order_id', $orderId)->where('state', PaymentRequestState::Open->value)->first());
    }

    public function lockOpenOf(string $orderId): ?PaymentRequest
    {
        return self::request($this->db->table('payments.requests')->where('order_id', $orderId)->where('state', PaymentRequestState::Open->value)->lockForUpdate()->first());
    }

    public function end(string $id, PaymentRequestState $state, DateTimeImmutable $now): void
    {
        $this->db->table('payments.requests')->where('id', $id)->where('state', PaymentRequestState::Open->value)->update(['state' => $state->value, 'updated_at' => $now]);
    }

    private static function request(mixed $row): ?PaymentRequest
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        return new PaymentRequest(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->order_id,
            (string) $row->order_number,
            (string) $row->customer_id,
            (int) $row->amount_minor,
            (string) $row->currency_code,
            PaymentMode::from((string) $row->mode),
            PaymentRequestState::from((string) $row->state),
            (new CarbonImmutable((string) $row->expires_at))->toDateTimeImmutable(),
            (string) $row->requested_by,
            (new CarbonImmutable((string) $row->created_at))->toDateTimeImmutable(),
        );
    }
}
