<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ChangeCartLine;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Enums\AccountType;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\Cart\CartInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A cart line's quantity (sales.md §1.1): from 1 to 1,000,000 — none is `RemoveCartLine` — of a line the
 * cart has, under the cart's lock; a signed-in individual never sets a wholesale one (answer 32). No
 * cart is ever made here.
 */
final readonly class ChangeCartLineHandler
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
     * @throws Unauthorized|InvalidSalesAttribute|NotForYourAccount
     */
    public function handle(ChangeCartLine $command): void
    {
        [$store, $customerId, $guestId, $accountType] = $this->buyer->of($command->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));
        $variantId = CartInputs::variant($command->variantId);
        $mode = CartInputs::mode($command->saleMode);
        $quantity = CartInputs::quantity($command->quantity);

        if ($mode === SaleMode::Wholesale && $accountType === AccountType::Individual) {
            throw new NotForYourAccount;
        }

        $changed = ($customerId !== null || $guestId !== null) && $this->db->transaction(function () use ($store, $customerId, $guestId, $variantId, $mode, $quantity): bool {
            $cart = $this->carts->lock($store, $customerId, $guestId);

            return $cart !== null && $this->carts->change($cart->id, $variantId, $mode, $quantity, CarbonImmutable::now());
        });

        if (! $changed) {
            throw new InvalidSalesAttribute('variant', 'not in the cart');
        }
    }
}
