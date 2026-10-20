<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RemoveCartLine;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\Cart\CartInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A line off the cart (sales.md §1.1), under the cart's lock: nothing when the cart has none — a guest
 * with no cart yet included. No cart and no guest is ever made here.
 */
final readonly class RemoveCartLineHandler
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
    public function handle(RemoveCartLine $command): void
    {
        [$store, $customerId, $guestId] = $this->buyer->of($command->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));
        $variantId = CartInputs::variant($command->variantId);
        $mode = CartInputs::mode($command->saleMode);

        if ($customerId === null && $guestId === null) {
            return;
        }

        $this->db->transaction(function () use ($store, $customerId, $guestId, $variantId, $mode): void {
            $cart = $this->carts->lock($store, $customerId, $guestId);

            if ($cart !== null) {
                $this->carts->remove($cart->id, $variantId, $mode, CarbonImmutable::now());
            }
        });
    }
}
