<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PayOnline;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission as LoyaltyCaller;
use Modules\Loyalty\Public\Dto\ReservePoints;
use Modules\Loyalty\Public\Enums\Audience as LoyaltyAudience;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CallerPermission as PromotionsCaller;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\ReserveCoupon;
use Modules\Promotions\Public\Enums\Audience as PromotionsAudience;
use Modules\Sales\Application\Checkout\QuoteComparison;
use Modules\Sales\Application\Checkout\TryAddress;
use Modules\Sales\Application\Checkout\TrySettling;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrderHandler;
use Modules\Sales\Application\Order\OrderContact;
use Modules\Sales\Application\Quote\QuoteBuilder;
use Modules\Sales\Application\Quote\QuoteRules;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\PaymentInProgress;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\QuoteNotFound;
use Modules\Sales\Domain\Exception\TooManyPaymentTries;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Domain\Model\CheckoutTry;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Repository\Quotes;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `PayOnline` (§9.5 #4). **One transaction**, its locks in placing's order (the quote, the customer's in the store,
 * the cart, then Promotions', Loyalty's and Inventory's last):
 *
 * 1. a live try on this cart: the same quote and session again is the same try (a double click), else
 *    `PaymentInProgress`;
 * 2. a quote with a kept try, or made before the cart last changed, refused (payments.md rule 3);
 * 3. the quote built again from the cart must equal it, else `QuoteChanged` naming what moved: nothing reserved;
 * 4. Payments' attempt written, no call to the gateway: no try ever exists without one that will be checked;
 * 5. the try written with its copies of the address and contact;
 * 6. the coupon and the points reserved, the stock held last, under the order's future id.
 *
 * **After the commit**, the charge (never in a transaction). A charge refused at once releases at once, so the
 * customer back on checkout may press Pay again.
 */
final readonly class PayOnlineHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_PLACE;

    /** 10 per cart per 10 minutes, and 30 per customer per hour (payments.md §1.8). One cart per store per customer. */
    public const int MOST_PER_CART = 10;

    public const int MOST_PER_CUSTOMER = 30;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private ConnectionInterface $db,
        private Quotes $quotes,
        private Orders $orders,
        private Carts $carts,
        private CheckoutTries $tries,
        private QuoteBuilder $builder,
        private QuoteRules $rules,
        private AccessApi $access,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private InventoryApi $inventory,
        private PaymentsApi $payments,
        private TrySettling $settling,
        private RateLimiter $limiter,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|QuoteNotFound|QuoteExpired|QuoteChanged|WayToPayNotOffered|PaymentInProgress|TooManyPaymentTries|DomainError
     */
    public function handle(PayOnline $command): OnlineStart
    {
        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSalesAttribute('store', 'not a store id');
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $customerId = strtolower($actor->id);
        $quoteId = strtolower(trim($command->quoteId));
        $sessionId = trim($command->sessionId);

        if (! Ulids::valid($quoteId)) {
            throw new QuoteNotFound;
        }

        if ($sessionId === '' || strlen($sessionId) > 100) {
            throw new InvalidSalesAttribute('session', 'a payment session');
        }

        $this->limit($store, $customerId);

        $try = $this->db->transaction(fn (): CheckoutTry => $this->start($store, $customerId, $quoteId, $sessionId, $command), PlaceOrderHandler::ATTEMPTS);
        $start = $this->payments->charge($try->attemptId);

        if (in_array($start->state, [PaymentState::Failed, PaymentState::Expired], true)) {
            $this->settling->act($try, new PaymentStateDto($start->state, $start->windowEndsAt, null, null, $start->nothingCharged, $start->attemptId));
        }

        return new OnlineStart($try->id, $start);
    }

    /** @throws TooManyPaymentTries */
    private function limit(StoreId $store, string $customerId): void
    {
        foreach ([["sales:pay:cart:{$store->value}:{$customerId}", self::MOST_PER_CART, 600], ["sales:pay:customer:{$customerId}", self::MOST_PER_CUSTOMER, 3600]] as [$key, $most, $seconds]) {
            if ($this->limiter->tooManyAttempts($key, $most)) {
                throw new TooManyPaymentTries($this->limiter->availableIn($key));
            }

            $this->limiter->hit($key, $seconds);
        }
    }

    /** @throws DomainError */
    private function start(StoreId $store, string $customerId, string $quoteId, string $sessionId, PayOnline $command): CheckoutTry
    {
        $quote = $this->quotes->lock($quoteId);

        if ($quote === null || $quote->customerId !== $customerId || $quote->storeId !== $store->value) {
            throw new QuoteNotFound;
        }

        if ($quote->wayToPay !== WayToPay::Online->value) {
            throw new WayToPayNotOffered;
        }

        $this->orders->lockCustomer($store->value, $customerId);
        $cart = $this->carts->lock($store, $customerId, null) ?? throw new QuoteChanged('cart');
        $live = $this->tries->liveOfCart($cart->id);

        if ($live !== null) {
            return $live->quoteId === $quoteId && $live->sessionId === $sessionId ? $live : throw new PaymentInProgress;
        }

        if ($this->tries->keptOfQuote($quoteId) !== null || $quote->cartVersion !== $cart->version) {
            throw new QuoteChanged('cart');
        }

        $now = CarbonImmutable::now();

        if ($now >= $quote->expiresAt) {
            throw new QuoteExpired;
        }

        $view = QuoteView::fromArray($quote->id, $quote->storeId, $quote->customerId, $quote->addressId, WayToPay::Online, $quote->expiresAt, $quote->createdAt, $quote->result);

        try {
            $moved = QuoteComparison::moved($view, $this->builder->build($store, $customerId, $quote->addressId, $quote->usePoints, $quote->pointsAsked, WayToPay::Online, $view->shipping->carrierId));

            if ($moved !== null) {
                throw new QuoteChanged($moved);
            }

            $address = $this->rules->address($store, $customerId, $quote->addressId);
            $customer = $this->access->customer($customerId) ?? throw new QuoteChanged('customer');
            $contact = OrderContact::of($customer);
            $tryId = strtolower((string) Str::ulid());
            $orderId = strtolower((string) Str::ulid());

            $attemptId = $this->payments->prepare(new PayRequestDto(
                $tryId, PaymentOwnerKind::Try, $orderId, $quoteId, $store, $customerId, $sessionId, $view->totals->orderTotal,
                $contact->name, $contact->email, $contact->phone, $command->locale, $command->returnUrl,
            ));

            $try = new CheckoutTry(
                $tryId, $store->value, $customerId, $cart->id, $cart->version, $quoteId, $orderId, $attemptId, $sessionId, CheckoutTry::LIVE,
                $view->totals->orderTotal->minorUnits, $view->totals->orderTotal->currencyCode,
                $now->addMinutes($this->payments->timers($store)->window), null, TryAddress::toArray($address), $contact->toArray(), null, false, $now, null,
            );
            $this->tries->add($try);
            $this->reserve($store, $customerId, $view, $orderId);
        } catch (QuoteChanged $changed) {
            throw $changed;
        } catch (DomainError $refused) {
            throw in_array($refused->type(), PlaceOrderHandler::REFUSALS, true) ? new QuoteChanged($refused->type(), $refused) : $refused;
        }

        return $try;
    }

    /**
     * The coupon, the points, and last, its lock the last taken, the stock: one line a size over both its
     * modes, and the gift's own. Held with no expiry, as at placing.
     *
     * @throws DomainError
     */
    private function reserve(StoreId $store, string $customerId, QuoteView $view, string $orderId): void
    {
        if ($view->couponId !== null && $view->couponCode !== null) {
            $this->promotions->reserveCoupon(new ReserveCoupon(
                $orderId, $view->couponId,
                new CouponRequest($store->value, $view->couponCode, $customerId, PromotionsAudience::from($view->audience->value), $view->prices),
                $view->couponDiscount, $view->pointsUsed > 0, new PromotionsCaller('sales', self::PERMISSION),
            ));
        }

        if ($view->pointsUsed > 0) {
            $this->loyalty->reserveRedemption(new ReservePoints(
                $orderId, $customerId, $store->value, $view->pointsUsed, $view->pointsDiscount,
                LoyaltyAudience::from($view->audience->value), $view->prices->netSubtotal, new LoyaltyCaller('sales', self::PERMISSION),
            ));
        }

        $pieces = [];

        foreach ($view->prices->lines as $line) {
            $pieces[$line->variantId] = ($pieces[$line->variantId] ?? 0) + $line->quantity;
        }

        $hold = array_map(static fn (string $variantId, int $quantity): HoldLineDto => new HoldLineDto($variantId, $quantity), array_keys($pieces), $pieces);

        if ($view->giftVariantId !== null) {
            $hold[] = new HoldLineDto($view->giftVariantId, 1, true);
        }

        $this->inventory->hold($store, $orderId, $hold, null);
    }
}
