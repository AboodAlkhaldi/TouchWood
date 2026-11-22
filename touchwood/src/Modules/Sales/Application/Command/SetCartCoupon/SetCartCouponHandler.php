<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\SetCartCoupon;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\SignInToUseCoupon;
use Modules\Sales\Domain\Exception\TooManyCouponTries;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The coupon code a customer typed (sales.md §1.1; owner, 2026-10-10): kept as typed — spaces around it
 * left off, at most 64 characters — and worked out at the quote; here only counted when it names no
 * coupon the customer may know of, five such in ten minutes (settings for every store) stopping the box
 * (owner, 2026-10-28); none,
 * or only spaces, takes it off. A guest's code box says "sign in to use a coupon" (`SignInToUseCoupon`).
 */
final readonly class SetCartCouponHandler
{
    /** A customer's cart; a guest's is `sales.guest_cart.manage` (CartBuyer). */
    public const string PERMISSION = SalesPermissions::CART_MANAGE;

    public function __construct(
        private CartBuyer $buyer,
        private Carts $carts,
        private ConnectionInterface $db,
        private Authorizer $authorizer,
        private PromotionsApi $promotions,
        private RateLimiter $limiter,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|SignInToUseCoupon|TooManyCouponTries
     */
    public function handle(SetCartCoupon $command): void
    {
        [$store, $customerId, $guestId] = $this->buyer->of($command->storeId);

        if ($customerId === null) {
            throw new SignInToUseCoupon;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $code = $command->code === null ? null : trim($command->code);
        $code = $code === '' ? null : $code;

        if ($code !== null && mb_strlen($code) > 64) {
            throw new InvalidSalesAttribute('coupon', 'at most 64 characters');
        }

        if ($code !== null) {
            $this->countWrong($store, $customerId, $code);
        }

        $this->db->transaction(function () use ($store, $customerId, $guestId, $code): void {
            $now = CarbonImmutable::now();
            $cart = $this->carts->lockOrCreate($store, $customerId, $guestId, $now);
            $this->carts->setCoupon($cart->id, $code, $now);
        });
    }

    /**
     * Codes that name no coupon the customer may know of — five in ten minutes unless an admin of every
     * store changed it (owner, 2026-10-28) — and the box takes none, a right one included, until the
     * window passes. A code given to others counts as wrong, as it answers as unknown. Taking the code
     * off is never counted.
     *
     * @throws TooManyCouponTries
     */
    private function countWrong(StoreId $store, string $customerId, string $code): void
    {
        $key = 'sales:coupon-wrong:'.$customerId;
        $most = $this->platform->setting(SalesSettings::COUPON_WRONG_CODES)->int();

        if ($this->limiter->tooManyAttempts($key, $most)) {
            throw new TooManyCouponTries($this->limiter->availableIn($key));
        }

        // The count the hit returns decides, not the look before it: tries sent at once each see it.
        if (! $this->promotions->knowsCoupon($store, $customerId, $code)
            && $this->limiter->hit($key, 60 * $this->platform->setting(SalesSettings::COUPON_WINDOW_MINUTES)->int()) > $most) {
            throw new TooManyCouponTries($this->limiter->availableIn($key));
        }
    }
}
