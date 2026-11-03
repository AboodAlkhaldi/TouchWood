<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission as LoyaltyCaller;
use Modules\Loyalty\Public\Dto\SettlePointsHold;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CallerPermission as PromotionsCaller;
use Modules\Promotions\Public\Dto\RecordOrder;
use Modules\Promotions\Public\Dto\SettleReservation;
use Modules\Sales\Application\Order\OnlineMoney;
use Modules\Sales\Application\Order\OrderCartEmptying;
use Modules\Sales\Application\Order\OrderContact;
use Modules\Sales\Application\Order\OrderSnapshot;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\CheckoutTryNotFound;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\CheckoutTry;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Modules\Sales\Domain\Repository\OrderNumbers;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Repository\Quotes;
use Modules\Sales\Public\Enums\WayToPay;
use Modules\Sales\Public\Events\OrderPlaced;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * `CompleteOnlineOrder` (§9.5 #4): a paid try's order made, **once**, under the try's lock, from its quote and
 * its copies. The number is taken now and by **the mode the payment was made in** (§9.5 #14). The quote's
 * expiry and the placing's checks are not asked again: the money was taken on it, and the reservations hold. A
 * store switched off meanwhile still gets the order (payments.md §1.10).
 *
 * The locks in placing's order: the quote, the customer's in the store, the cart, then the try. An online checkout's order sends no
 * `PaymentRecorded`: its customer has one email, the order's own (ops.md C1).
 */
final readonly class TryCompletion
{
    public const int ATTEMPTS = 3;

    public function __construct(
        private ConnectionInterface $db,
        private CheckoutTries $tries,
        private Quotes $quotes,
        private Carts $carts,
        private Orders $orders,
        private OrderNumbers $numbers,
        private OrderSnapshot $snapshot,
        private OrderCartEmptying $emptying,
        private OnlineMoney $money,
        private PlatformApi $platform,
        private AccessApi $access,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private PaymentsApi $payments,
        private Outbox $outbox,
    ) {}

    /** The page's own answer: the order made if the try is live, and nothing else. */
    public function complete(string $tryId, string $paymentId): void
    {
        $this->paid($tryId, $this->payment($paymentId), false);
    }

    /**
     * `PaymentSucceeded`, told once per event (§9.5 #4, #7): the order; a second success on a paid try is a
     * second payment; a success after a release makes no order by itself and is a refund due.
     */
    public function told(string $tryId, string $paymentId): void
    {
        $payment = $this->payment($paymentId);

        // A request on the order this try made is that request's money, and `RecordRequestPayment`'s (§9.5 #8).
        if ($payment->ownerKind === PaymentOwnerKind::Request) {
            return;
        }

        $this->paid($tryId, $payment, true);
    }

    private function payment(string $paymentId): PaymentDto
    {
        return $this->payments->payment($paymentId) ?? throw new LogicException("No payment {$paymentId} of the gateway's.");
    }

    private function paid(string $tryId, PaymentDto $payment, bool $told): void
    {
        $seen = $this->tries->find($tryId) ?? throw new CheckoutTryNotFound;
        $store = StoreId::fromString($seen->storeId);

        $this->db->transaction(function () use ($seen, $store, $payment, $told): void {
            $quote = $this->quotes->lock($seen->quoteId);
            $this->orders->lockCustomer($seen->storeId, $seen->customerId);
            $cart = $this->carts->lock($store, $seen->customerId, null);
            $try = $this->tries->lock($seen->id) ?? throw new CheckoutTryNotFound;

            if ($try->isLive()) {
                $this->make($try, $store, $quote === null ? throw new LogicException("A live try without its quote: {$try->id}.") : QuoteView::fromArray($quote->id, $quote->storeId, $quote->customerId, $quote->addressId, WayToPay::from($quote->wayToPay), $quote->expiresAt, $quote->createdAt, $quote->result), $cart, $payment);

                return;
            }

            if (! $told) {
                return;
            }

            $try->state === CheckoutTry::PAID ? $this->money->record($try->orderId, $payment) : $this->tries->addRefundDue($try->id, $payment->amount->minorUnits);
        }, self::ATTEMPTS);
    }

    private function make(CheckoutTry $try, StoreId $store, QuoteView $view, ?Cart $cart, PaymentDto $payment): void
    {
        $now = CarbonImmutable::now();
        $isTest = $payment->mode === PaymentMode::Test;
        $code = $this->platform->store($store)->code ?? throw new LogicException("No store {$store->value}.");
        $number = $isTest ? sprintf('TEST-%04d', $this->numbers->nextTest($store)) : sprintf('%s-%04d', strtoupper($code), $this->numbers->next($store));
        $promotionsCaller = new PromotionsCaller('sales', SalesPermissions::ORDER_PLACE);

        $this->orders->add($this->snapshot->of($view, $try->orderId, $number, OrderContact::fromArray($try->contact ?? []), self::address($try), $now, $isTest));
        $this->orders->recordHistory($try->orderId, $try->storeId, 'PLACED', null, null, $now, ['number' => $number, 'quoteId' => $try->quoteId]);

        // The stock was held at Pay under this very id: the order keeps it. A flag the try received goes with it.
        if ($try->shortOfStockAt !== null) {
            $this->orders->flagShort($try->storeId, [$try->orderId], CarbonImmutable::instance($try->shortOfStockAt));
            $this->orders->recordHistory($try->orderId, $try->storeId, 'SHORT_FLAGGED', null, null, $now, ['duringPayment' => true]);
        }

        if ($view->couponId !== null) {
            $this->promotions->confirmCoupon(new SettleReservation($try->storeId, $try->orderId, $number, $promotionsCaller));
        }

        if ($view->pointsUsed > 0) {
            $this->loyalty->confirmRedemption(new SettlePointsHold($try->storeId, $try->orderId, $number, new LoyaltyCaller('sales', SalesPermissions::ORDER_PLACE)));
        }

        if (! $isTest) {
            $this->promotions->recordOrder(new RecordOrder($try->orderId, $try->customerId, $try->storeId, $now, $promotionsCaller));
        }

        if ($cart !== null) {
            $this->emptying->of($cart, $view, $now);
        }

        $this->tries->end($try->id, CheckoutTry::PAID, $now);
        $this->money->record($try->orderId, $payment);

        // An account anonymized during the few minutes of the payment keeps no email on its order either.
        if ($this->access->customer($try->customerId)?->anonymized === true) {
            $this->orders->forgetEmail($try->customerId);
        }

        $this->outbox->record(new OrderPlaced((string) Str::uuid(), $try->orderId, $try->storeId, $try->customerId, $now));
    }

    private static function address(CheckoutTry $try): AddressDto
    {
        return TryAddress::fromArray($try->address ?? throw new LogicException("A live try without its address: {$try->id}."));
    }
}
