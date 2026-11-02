<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Request;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Domain\Exception\AmountChanged;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoOnlinePayment;
use Modules\Payments\Domain\Exception\PaymentInProgress;
use Modules\Payments\Domain\Exception\RequestNotOpen;
use Modules\Payments\Domain\Model\PaymentRequest;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Domain\Repository\PaymentRequests;
use Modules\Payments\Public\Dto\PaymentRequestDto;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentRequestState;
use Modules\Payments\Public\Events\PaymentRequested;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\Money;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * A staff request for the rest of an order (payments.md §1.5). **One lock settles every race on it**: Sales locks the
 * order first, then this locks the request row, and only then looks at the attempts. Time passing ends a request only
 * here, when it is next looked at, and **never while a payment on it is open**: its 60 minutes wait for that answer.
 */
final readonly class OrderPaymentRequests
{
    public const int OPEN_MINUTES = 60;

    public function __construct(
        private PaymentRequests $requests,
        private Attempts $attempts,
        private Gateways $gateways,
        private PlatformApi $platform,
        private Outbox $outbox,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NoOnlinePayment|InvalidPaymentsInput|PaymentInProgress|AmountChanged
     */
    public function request(PaymentRequestDto $request, string $staffId): PaymentRequestStateDto
    {
        return $this->db->transaction(function () use ($request, $staffId): PaymentRequestStateDto {
            $store = $this->platform->store($request->storeId) ?? throw new NoOnlinePayment;
            $gateway = $this->gateways->of($store->id);
            $key = $gateway === null ? null : $this->gateways->currentKey($store->id, $gateway->mode);

            // Real money must never land on a test order, nor a test payment on a real one.
            if ($gateway === null || ! $gateway->active || $key === null || $key->rejected || $gateway->mode !== $request->mode) {
                throw new NoOnlinePayment;
            }

            if ($request->amount->currencyCode !== $store->currencyCode || ! $request->amount->isPositive()) {
                throw new InvalidPaymentsInput('amount', 'more than zero, in the store\'s currency');
            }

            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $open = $this->requests->lockOpenOf($request->orderId);

            if ($this->attempts->openOfOrder($request->orderId) !== null) {
                throw new PaymentInProgress;
            }

            if ($open !== null) {
                if ($now < $open->expiresAt) {
                    return self::sameAmount($open, $request->amount);
                }

                $this->requests->end($open->id, PaymentRequestState::Expired, $now);
            }

            $new = new PaymentRequest(
                strtolower((string) Str::ulid()),
                $store->id,
                $request->orderId,
                $request->orderNumber,
                $request->customerId,
                $request->amount->minorUnits,
                $request->amount->currencyCode,
                $request->mode,
                PaymentRequestState::Open,
                CarbonImmutable::instance($now)->addMinutes(self::OPEN_MINUTES)->toDateTimeImmutable(),
                $staffId,
                $now,
            );

            // Two at the same instant, whatever got past the order's lock: the index keeps one.
            if (! $this->requests->add($new)) {
                $won = $this->requests->openOf($request->orderId) ?? throw new AmountChanged;

                return self::sameAmount($won, $request->amount);
            }

            $this->outbox->record(new PaymentRequested((string) Str::uuid(), $new->id, $new->orderId, $new->storeId, $new->customerId, $new->expiresAt, $now));

            return self::state($new);
        });
    }

    /** False while a payment on it is open; true when none is left open (§1.5). */
    public function withdraw(string $orderId): bool
    {
        return $this->db->transaction(function () use ($orderId): bool {
            $open = $this->requests->lockOpenOf($orderId);

            if ($open === null) {
                return true;
            }

            if ($this->attempts->openOf($open->id) !== null) {
                return false;
            }

            $this->requests->end($open->id, PaymentRequestState::Withdrawn, CarbonImmutable::now()->toDateTimeImmutable());

            return true;
        });
    }

    /**
     * The order was cancelled (§6): its request goes whatever is open on it. A payment already in progress is still
     * answered and recorded, a refund due for Sales to show.
     */
    public function orderCancelled(string $orderId): void
    {
        $this->db->transaction(function () use ($orderId): void {
            $open = $this->requests->lockOpenOf($orderId);

            if ($open !== null) {
                $this->requests->end($open->id, PaymentRequestState::Withdrawn, CarbonImmutable::now()->toDateTimeImmutable());
            }
        });
    }

    public function open(string $orderId): ?PaymentRequestStateDto
    {
        $open = $this->requests->openOf($orderId);

        if ($open === null) {
            return null;
        }

        if (CarbonImmutable::now() < $open->expiresAt) {
            return self::state($open);
        }

        return $this->db->transaction(function () use ($orderId): ?PaymentRequestStateDto {
            $open = $this->requests->lockOpenOf($orderId);

            if ($open === null) {
                return null;
            }

            if ($this->attempts->openOf($open->id) !== null) {
                return self::state($open);
            }

            $this->requests->end($open->id, PaymentRequestState::Expired, CarbonImmutable::now()->toDateTimeImmutable());

            return null;
        });
    }

    /**
     * `prepare` for a request (§1.5): its row locked, open, and the amount, the buyer and the mode its own (rule 12).
     * An open attempt on it is left for `prepare` to answer: the same session again is a double click.
     *
     * @throws RequestNotOpen|AmountChanged
     */
    public function preparing(PayRequestDto $pay, PaymentMode $gatewayMode, DateTimeImmutable $now): void
    {
        $request = $this->requests->lock($pay->ownerId);

        if ($request === null
            || $request->state !== PaymentRequestState::Open
            || $request->orderId !== $pay->orderId
            || $request->customerId !== $pay->buyerId
            || $request->storeId !== $pay->storeId->value
            || $request->mode !== $gatewayMode) {
            throw new RequestNotOpen;
        }

        if ($this->attempts->openOf($request->id) !== null) {
            return;
        }

        if ($now >= $request->expiresAt) {
            throw new RequestNotOpen;
        }

        if ($request->amountMinor !== $pay->amount->minorUnits || $request->currencyCode !== $pay->amount->currencyCode) {
            throw new AmountChanged;
        }
    }

    /** @throws AmountChanged */
    private static function sameAmount(PaymentRequest $open, Money $amount): PaymentRequestStateDto
    {
        return $open->amountMinor === $amount->minorUnits && $open->currencyCode === $amount->currencyCode ? self::state($open) : throw new AmountChanged;
    }

    private static function state(PaymentRequest $request): PaymentRequestStateDto
    {
        return new PaymentRequestStateDto(
            $request->id,
            $request->orderId,
            Money::of($request->amountMinor, $request->currencyCode),
            $request->expiresAt,
            $request->state,
            $request->requestedBy,
            $request->mode,
        );
    }
}
