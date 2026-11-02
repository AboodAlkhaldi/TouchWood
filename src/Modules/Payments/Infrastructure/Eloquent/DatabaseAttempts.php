<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use stdClass;

final readonly class DatabaseAttempts implements Attempts
{
    private const array OPEN = ['CREATING', 'CHARGING', 'OPEN', 'UNKNOWN'];

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(Attempt $attempt, ?string $customerName = null, ?string $customerEmail = null, ?string $customerPhone = null): void
    {
        $this->db->table('payments.attempts')->insert([
            'id' => $attempt->id,
            'store_id' => $attempt->storeId,
            'gateway' => 'MYFATOORAH',
            'owner_kind' => $attempt->ownerKind->value,
            'owner_id' => $attempt->ownerId,
            'order_id' => $attempt->orderId,
            'buyer_id' => $attempt->buyerId,
            'session_id' => $attempt->sessionId,
            'key_id' => $attempt->keyId,
            'mode' => $attempt->mode->value,
            'currency_code' => $attempt->currencyCode,
            'amount_minor' => $attempt->amountMinor,
            'state' => $attempt->state->value,
            'idempotency_key' => $attempt->idempotencyKey,
            'locale' => $attempt->locale,
            'return_url' => $attempt->returnUrl,
            'window_ends_at' => $attempt->windowEndsAt,
            'wait_ends_at' => $attempt->waitEndsAt,
            'hold_ends_at' => $attempt->holdEndsAt,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'created_at' => $attempt->createdAt,
            'updated_at' => $attempt->createdAt,
        ]);
    }

    public function find(string $id): ?Attempt
    {
        return self::attempt($this->db->table('payments.attempts')->where('id', strtolower($id))->first());
    }

    public function lock(string $id): ?Attempt
    {
        return self::attempt($this->db->table('payments.attempts')->where('id', strtolower($id))->lockForUpdate()->first());
    }

    public function save(Attempt $attempt, DateTimeImmutable $now): void
    {
        $this->db->table('payments.attempts')->where('id', $attempt->id)->update([
            'state' => $attempt->state->value,
            'charge_claimed_at' => $attempt->chargeClaimedAt,
            'invoice_id' => $attempt->invoiceId,
            'gateway_payment_id' => $attempt->gatewayPaymentId,
            'frame_url' => $attempt->frameUrl,
            'next_check_at' => $attempt->nextCheckAt,
            'last_checked_at' => $attempt->lastCheckedAt,
            'succeeded_at' => $attempt->succeededAt,
            'paid_minor' => $attempt->paidMinor,
            'mismatch' => $attempt->mismatch,
            'paid_twice' => $attempt->paidTwice,
            'needs_attention' => $attempt->needsAttention,
            'nothing_charged' => $attempt->nothingCharged,
            'refused_reason' => $attempt->refusedReason,
            'currency_refused' => $attempt->currencyRefused,
            'transaction_id' => $attempt->transactionId,
            'updated_at' => $now,
        ]);
    }

    public function openOf(string $ownerId): ?Attempt
    {
        return self::attempt($this->db->table('payments.attempts')->where('owner_id', $ownerId)->whereIn('state', self::OPEN)->first());
    }

    public function latestOf(string $ownerId): ?Attempt
    {
        return self::attempt($this->db->table('payments.attempts')->where('owner_id', $ownerId)->orderByDesc('created_at')->orderByDesc('id')->first());
    }

    public function byInvoice(PaymentMode $mode, string $invoiceId): ?Attempt
    {
        return self::attempt($this->db->table('payments.attempts')->where('gateway', 'MYFATOORAH')->where('mode', $mode->value)->where('invoice_id', $invoiceId)->first());
    }

    public function claimCheck(string $id, DateTimeImmutable $now, DateTimeImmutable $until): bool
    {
        return $this->db->table('payments.attempts')
            ->where('id', $id)
            ->where(fn ($query) => $query->whereNull('checking_until')->orWhere('checking_until', '<', $now))
            ->update(['checking_until' => $until]) === 1;
    }

    public function releaseCheck(string $id): void
    {
        $this->db->table('payments.attempts')->where('id', $id)->update(['checking_until' => null]);
    }

    public function overdue(DateTimeImmutable $before, int $most): array
    {
        /** @var list<string> $ids */
        $ids = $this->db->table('payments.attempts')->whereNotNull('next_check_at')->where('next_check_at', '<', $before)->orderBy('next_check_at')->limit($most)->pluck('id')->all();

        return $ids;
    }

    public function clearCustomer(string $buyerId, DateTimeImmutable $now): void
    {
        $this->db->table('payments.attempts')
            ->where('buyer_id', $buyerId)
            ->where(fn ($query) => $query->whereNotNull('customer_name')->orWhereNotNull('customer_email')->orWhereNotNull('customer_phone'))
            ->update(['customer_name' => null, 'customer_email' => null, 'customer_phone' => null, 'updated_at' => $now]);
    }

    private static function attempt(mixed $row): ?Attempt
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        return new Attempt(
            (string) $row->id,
            (string) $row->store_id,
            PaymentOwnerKind::from((string) $row->owner_kind),
            (string) $row->owner_id,
            (string) $row->order_id,
            (string) $row->buyer_id,
            (string) $row->session_id,
            (string) $row->key_id,
            PaymentMode::from((string) $row->mode),
            (string) $row->currency_code,
            (int) $row->amount_minor,
            AttemptState::from((string) $row->state),
            (string) $row->idempotency_key,
            (string) $row->locale,
            (string) $row->return_url,
            self::moment($row->window_ends_at) ?? new CarbonImmutable,
            self::moment($row->wait_ends_at) ?? new CarbonImmutable,
            self::moment($row->hold_ends_at) ?? new CarbonImmutable,
            self::moment($row->created_at) ?? new CarbonImmutable,
            self::moment($row->charge_claimed_at),
            $row->invoice_id === null ? null : (string) $row->invoice_id,
            $row->gateway_payment_id === null ? null : (string) $row->gateway_payment_id,
            $row->frame_url === null ? null : (string) $row->frame_url,
            self::moment($row->next_check_at),
            self::moment($row->last_checked_at),
            self::moment($row->succeeded_at),
            $row->paid_minor === null ? null : (int) $row->paid_minor,
            (bool) $row->mismatch,
            (bool) $row->paid_twice,
            (bool) $row->needs_attention,
            (bool) $row->nothing_charged,
            $row->refused_reason === null ? null : (string) $row->refused_reason,
            (bool) $row->currency_refused,
            $row->transaction_id === null ? null : (string) $row->transaction_id,
        );
    }

    private static function moment(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : (new CarbonImmutable((string) $value))->toDateTimeImmutable();
    }
}
