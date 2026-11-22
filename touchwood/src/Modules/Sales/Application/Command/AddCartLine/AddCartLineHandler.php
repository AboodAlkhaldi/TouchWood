<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\AddCartLine;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
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
 * signed-in individual never buys wholesale. A guest's first line makes the guest — only once it has
 * passed every check (`CartBuyer::ensureGuest`). A size Catalog deletes between the check and the line
 * is refused as no size, never a database error.
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
        [$store, $customerId, $guestId, $accountType] = $this->buyer->of($command->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));
        $variantId = CartInputs::variant($command->variantId);
        $mode = CartInputs::mode($command->saleMode);
        $quantity = CartInputs::quantity($command->quantity);

        if ($mode === SaleMode::Wholesale && $accountType === AccountType::Individual) {
            throw new NotForYourAccount;
        }

        if ($this->catalog->variants([$variantId]) === []) {
            throw new InvalidSalesAttribute('variant', 'not a size');
        }

        $guestId = $customerId === null ? ($guestId ?? $this->buyer->ensureGuest()) : null;

        try {
            $this->db->transaction(function () use ($store, $customerId, $guestId, $variantId, $mode, $quantity): void {
                $now = CarbonImmutable::now();
                $cart = $this->carts->lockOrCreate($store, $customerId, $guestId, $now);
                $this->carts->add($cart->id, $variantId, $mode, $quantity, $now);
            });
        } catch (QueryException $failed) {
            if ($failed->getCode() === '23503' && str_contains($failed->getMessage(), 'cart_lines_variant')) {
                throw new InvalidSalesAttribute('variant', 'removed from the catalog meanwhile');
            }

            throw $failed;
        }
    }
}
