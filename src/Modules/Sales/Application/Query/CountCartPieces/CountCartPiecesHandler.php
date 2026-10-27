<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\CountCartPieces;

use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The header's piece count** (sales.md §4.2 B13): the pieces on the shopper's cart lines in a store, over
 * both modes and every line as it is (one that cannot be ordered counts too, as it is in the cart) — a
 * customer's or a guest's, under the cart's own permission. One read; 0 with no cart, for a browser not yet
 * a guest, and for anyone who has no cart at all (staff), so every shop page can ask.
 */
final readonly class CountCartPiecesHandler
{
    public function __construct(
        private ActorContext $actors,
        private Authorizer $authorizer,
        private CartBuyer $buyer,
        private Carts $carts,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(CountCartPieces $query): int
    {
        if (! in_array($this->actors->current()->type, [ActorType::Customer, ActorType::Guest], true)) {
            return 0;
        }

        [$store, $customerId, $guestId] = $this->buyer->who($query->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : SalesPermissions::CART_MANAGE, PermissionScope::store($store));

        return $customerId === null && $guestId === null ? 0 : $this->carts->pieces($store, $customerId, $guestId);
    }
}
