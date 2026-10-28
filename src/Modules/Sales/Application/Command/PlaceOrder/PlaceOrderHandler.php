<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PlaceOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission as LoyaltyCaller;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Enums\Audience as LoyaltyAudience;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CallerPermission as PromotionsCaller;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\RecordOrder;
use Modules\Promotions\Public\Dto\UseCoupon;
use Modules\Promotions\Public\Enums\Audience as PromotionsAudience;
use Modules\Sales\Application\Order\OrderSnapshot;
use Modules\Sales\Application\Quote\QuoteRules;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\QuoteNotFound;
use Modules\Sales\Domain\Exception\TooManyWaitingOrders;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\PlacedOrder;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\OrderNumbers;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Repository\Quotes;
use Modules\Sales\Public\Enums\WayToPay;
use Modules\Sales\Public\Events\OrderPlaced;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Placing a quote (sales.md §1.3) — **one transaction**, its locks in one order: Sales's own first (the
 * quote, the customer's in the store, then the cart), then Promotions' and Loyalty's, Inventory's last — no lock on an existing row
 * after it, only new rows (the cart's own row already locked); a deadlock tried again (`ATTEMPTS`):
 *
 * 1. the quote locked; an order already placed from it answered as it is — before the expiry, so a
 *    retry after 15 minutes still gets its order; then the expiry; then, under a lock per customer and
 *    store, fewer orders waiting for approval than allowed (`TooManyWaitingOrders`, answer 51 — shown as
 *    itself, not as `QuoteChanged`);
 * 2. the store's next number;
 * 3. every rule that can change in 15 minutes, again, on the quote's own lines — the store, the
 *    account, the lines, the address, the way to pay, **the gift as quoted**, **the coupon and points
 *    within the discount room** (answer 37);
 * 4. the order written, snapshotted;
 * 5. the coupon used, the points redeemed, the order's fact recorded, the stock held (no expiry);
 * 6. the bought pieces and the coupon leave the cart (my assumption, stated for the owner to reject);
 * 7. `OrderPlaced` into the outbox.
 *
 * **Any refusal rolls everything back** and is `QuoteChanged`, naming what moved (§1.3) — only the
 * refusals of `REFUSALS`: anything else is a fault, and goes on as itself.
 */
final readonly class PlaceOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_PLACE;

    /**
     * The refusals that mean something moved since the quote (§1.3, §7) — every other error, a bug or a
     * fault (a module's own input check, a permission), goes on as itself, never hidden as `QuoteChanged`.
     */
    public const array REFUSALS = [
        'sales.may_not_order', 'sales.not_for_your_account', 'sales.not_orderable', 'sales.address_not_usable',
        'promotions.coupon_refused', 'promotions.coupon_changed',
        'loyalty.redemption_refused', 'loyalty.redemption_changed', 'loyalty.points_programme_off',
        'inventory.not_enough_stock',
    ];

    /** A deadlock with a Catalog write meanwhile is tried again (Inventory's hold takes Catalog's lock). */
    public const int ATTEMPTS = 3;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private ConnectionInterface $db,
        private Quotes $quotes,
        private Orders $orders,
        private OrderNumbers $numbers,
        private Carts $carts,
        private QuoteRules $rules,
        private OrderSnapshot $snapshot,
        private PlatformApi $platform,
        private AccessApi $access,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private InventoryApi $inventory,
        private Outbox $outbox,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|QuoteNotFound|QuoteExpired|TooManyWaitingOrders|QuoteChanged
     */
    public function handle(PlaceOrder $command): PlacedOrder
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

        if (! Ulids::valid($quoteId)) {
            throw new QuoteNotFound;
        }

        return $this->db->transaction(function () use ($store, $customerId, $quoteId): PlacedOrder {
            // 1. The quote, locked: placing twice is placing once.
            $quote = $this->quotes->lock($quoteId);

            if ($quote === null || $quote->customerId !== $customerId || $quote->storeId !== $store->value) {
                throw new QuoteNotFound;
            }

            $placed = $this->orders->placedFrom($quoteId);

            if ($placed !== null) {
                return $placed;
            }

            $now = CarbonImmutable::now();

            if ($now >= $quote->expiresAt) {
                throw new QuoteExpired;
            }

            // The orders already waiting, counted with the customer's other placements in the store kept out.
            $this->orders->lockCustomer($store->value, $customerId);
            $this->rules->waiting($store, $customerId);

            $view = QuoteView::fromArray($quote->id, $quote->storeId, $quote->customerId, $quote->addressId, WayToPay::from($quote->wayToPay), $quote->expiresAt, $quote->createdAt, $quote->result);
            $cart = $this->carts->lock($store, $customerId, null);

            // 2. The number, taken first: Promotions and Loyalty record it.
            $code = $this->platform->store($store)->code ?? throw new QuoteChanged('store');
            // Four digits at least (0001 … 9999, then 10000): answer 35 as changed 2026-10-26.
            $number = sprintf('%s-%04d', strtoupper($code), $this->numbers->next($store));
            $orderId = strtolower((string) Str::ulid());

            try {
                // 3. The rules again, on the quote's own lines.
                $address = $this->recheck($store, $customerId, $view);

                // 4. The order, snapshotted.
                $customer = $this->access->customer($customerId) ?? throw new QuoteChanged('customer');
                $this->orders->add($this->snapshot->of($view, $orderId, $number, $customer, $address, $now));
                $this->orders->recordHistory($orderId, $store->value, 'PLACED', null, null, $now, ['number' => $number, 'quoteId' => $quoteId]);

                // 5. The other modules, Inventory last.
                $this->others($store, $customerId, $view, $orderId, $number, $now);
            } catch (QuoteChanged $changed) {
                throw $changed;
            } catch (DomainError $refused) {
                throw in_array($refused->type(), self::REFUSALS, true) ? new QuoteChanged($refused->type(), $refused) : $refused;
            }

            // 6. The bought pieces leave the cart — what was added since stays — and the coupon, when it is
            // still the one quoted.
            if ($cart !== null) {
                $this->emptyOf($cart, $view, $now);
            }

            // 7. Told, with the change.
            $this->outbox->record(new OrderPlaced((string) Str::uuid(), $orderId, $store->value, $customerId, $now));

            return new PlacedOrder($orderId, $number);
        }, self::ATTEMPTS);
    }

    /** The quoted pieces of each line out of the cart, a line gone when none are left; the quoted coupon. */
    private function emptyOf(Cart $cart, QuoteView $view, CarbonImmutable $now): void
    {
        foreach ($view->prices->lines as $quoted) {
            foreach ($cart->lines as $line) {
                if ($line->variantId !== $quoted->variantId || $line->mode !== $quoted->mode) {
                    continue;
                }

                $left = $line->quantity - $quoted->quantity;
                $left > 0 ? $this->carts->change($cart->id, $line->variantId, $line->mode, $left, $now) : $this->carts->remove($cart->id, $line->variantId, $line->mode, $now);
            }
        }

        if ($cart->couponCode !== null && $cart->couponCode === $view->couponCode) {
            $this->carts->setCoupon($cart->id, null, $now);
        }
    }

    /**
     * §1.3 step 3: what can change in 15 minutes, checked again.
     *
     * @throws DomainError
     */
    private function recheck(StoreId $store, string $customerId, QuoteView $view): AddressDto
    {
        $audience = $this->rules->audience($store, $customerId);

        // Unreachable today — an account's type never changes, and a company no longer approved may not
        // order at all — kept so a quote is never placed for another audience than it was priced for.
        if ($audience !== $view->audience) {
            throw new QuoteChanged('audience');
        }

        $lines = array_map(static fn (LinePriceDto $line): CartLineDto => new CartLineDto($line->variantId, $line->mode, $line->quantity), $view->prices->lines);
        $this->rules->lines($store, $lines, $audience);
        $address = $this->rules->address($store, $customerId, $view->addressId);

        if (! in_array($view->wayToPay, $this->rules->waysToPay($store, $audience), true)) {
            throw new QuoteChanged('way_to_pay');
        }

        if ($this->rules->gift($store, $audience, $view->totals->goodsTotal, $lines) !== [$view->giftLevelId, $view->giftVariantId]) {
            throw new QuoteChanged('gift');
        }

        $room = $this->promotions->customerDiscountRoom($store, $view->prices->grossSubtotal, $view->prices->netSubtotal);

        if ($view->couponDiscount->add($view->pointsDiscount)->compareTo($room) > 0) {
            throw new QuoteChanged('ceiling');
        }

        return $address;
    }

    /**
     * §1.3 steps 4–6: the coupon used, the points redeemed, the order's fact recorded, and — last, its
     * lock the last taken — the stock held: one line a size over both its modes, and the gift's own.
     *
     * @throws DomainError
     */
    private function others(StoreId $store, string $customerId, QuoteView $view, string $orderId, string $number, CarbonImmutable $now): void
    {
        $promotionsCaller = new PromotionsCaller('sales', self::PERMISSION);

        if ($view->couponId !== null && $view->couponCode !== null) {
            $this->promotions->useCoupon(new UseCoupon(
                $orderId, $number, $view->couponId,
                new CouponRequest($store->value, $view->couponCode, $customerId, PromotionsAudience::from($view->audience->value), $view->prices),
                $view->couponDiscount, $view->pointsUsed > 0, $promotionsCaller,
            ));
        }

        if ($view->pointsUsed > 0) {
            $this->loyalty->redeem(new RedeemPoints(
                $orderId, $number, $customerId, $store->value, $view->pointsUsed, $view->pointsDiscount,
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

        $this->promotions->recordOrder(new RecordOrder($orderId, $customerId, $store->value, $now, $promotionsCaller));
        $this->inventory->hold($store, $orderId, $hold, null);
    }
}
