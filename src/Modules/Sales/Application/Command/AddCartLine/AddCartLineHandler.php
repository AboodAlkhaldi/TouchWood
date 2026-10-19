<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\AddCartLine;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Enums\AccountType;
use Modules\Catalog\Public\Contracts\CatalogApi;
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
 * A size into the cart (sales.md §1.1): **permissive** — any size Catalog has, in either mode, any
 * quantity from 1 to 1,000,000; the quote checks the rest. Added to a line of the same size and mode
 * when there is one. **One exception, the account's own view** (owner, 2026-10-19, answer 32): a
 * signed-in individual never buys wholesale. A guest's first line creates the guest (`GuestIdentity`).
 */
final readonly class AddCartLineHandler
{
    /** A customer's cart; a guest's is `sales.guest_cart.manage` (CartBuyer). */
    public const string PERMISSION = SalesPermissions::CART_MANAGE;

    public function __construct(
        private CartBuyer $buyer,
        private Carts $carts,
        private ConnectionInterface $db,
        private CatalogApi $catalog,
        private Authorizer $authorizer,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|NotForYourAccount
     */
    public function handle(AddCartLine $command): void
    {
        // Authorized in CartBuyer for this buyer before any input is read, and asked again here.
        [$store, $customerId, $guestId, $accountType] = $this->buyer->of($command->storeId, true);
        $variantId = CartInputs::variant($command->variantId);
        $mode = CartInputs::mode($command->saleMode);
        $quantity = CartInputs::quantity($command->quantity);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));

        if ($mode === SaleMode::Wholesale && $accountType === AccountType::Individual) {
            throw new NotForYourAccount;
        }

        if ($this->catalog->variants([$variantId]) === []) {
            throw new InvalidSalesAttribute('variant', 'not a size');
        }

        $this->db->transaction(function () use ($store, $customerId, $guestId, $variantId, $mode, $quantity): void {
            $now = CarbonImmutable::now();
            $cart = $this->carts->lockOrCreate($store, $customerId, $guestId, $now);
            $this->carts->add($cart->id, $variantId, $mode, $quantity, $now);
        });
    }
}
