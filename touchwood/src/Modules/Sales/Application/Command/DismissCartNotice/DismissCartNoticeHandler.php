<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\DismissCartNotice;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The cart's notice closed for good (sales.md §4.1 S4, §4.2 B17; owner, 2026-10-27): the lines that left
 * at a sign-in are named until the buyer closes them, then never again. Under the cart's lock; nothing
 * when there is no cart or no notice. No cart and no guest is ever made here.
 */
final readonly class DismissCartNoticeHandler
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
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(DismissCartNotice $command): void
    {
        [$store, $customerId, $guestId] = $this->buyer->who($command->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));

        if ($customerId === null && $guestId === null) {
            return;
        }

        $this->db->transaction(function () use ($store, $customerId, $guestId): void {
            $cart = $this->carts->lock($store, $customerId, $guestId);

            if ($cart !== null && $cart->notice !== []) {
                $this->carts->setNotice($cart->id, [], CarbonImmutable::now());
            }
        });
    }
}
