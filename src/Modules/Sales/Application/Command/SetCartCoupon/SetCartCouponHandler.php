<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\SetCartCoupon;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\SignInToUseCoupon;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The coupon code a customer typed (sales.md §1.1; owner, 2026-10-10): kept as typed — spaces around it
 * left off, at most 64 characters — and tried at the quote, never here; none, or only spaces, takes it
 * off. A guest's code box says "sign in to use a coupon" (`SignInToUseCoupon`).
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
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|SignInToUseCoupon
     */
    public function handle(SetCartCoupon $command): void
    {
        [$store, $customerId, $guestId] = $this->buyer->of($command->storeId, false);

        if ($customerId === null) {
            throw new SignInToUseCoupon;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $code = $command->code === null ? null : trim($command->code);
        $code = $code === '' ? null : $code;

        if ($code !== null && mb_strlen($code) > 64) {
            throw new InvalidSalesAttribute('coupon', 'at most 64 characters');
        }

        $this->db->transaction(function () use ($store, $customerId, $guestId, $code): void {
            $now = CarbonImmutable::now();
            $cart = $this->carts->lockOrCreate($store, $customerId, $guestId, $now);
            $this->carts->setCoupon($cart->id, $code, $now);
        });
    }
}
